#!/usr/bin/env php
<?php

/**
 * Asterisk Integration – background service daemon.
 *
 * Connects to the Asterisk Manager Interface (AMI), listens for every call
 * event in real time, and forwards relevant events to the CRM via HTTP.
 *
 * Run directly:
 *   php service.php
 *
 * Or managed by systemd (see asterisk-integration.service).
 */

declare(strict_types=1);

require_once __DIR__ . '/src/Config.php';
require_once __DIR__ . '/src/Logger.php';
require_once __DIR__ . '/src/ViciDialClient.php';
require_once __DIR__ . '/src/AsteriskEventListener.php';

use Asterisk\Integration\AsteriskEventListener;
use Asterisk\Integration\Config;
use Asterisk\Integration\Logger;
use Asterisk\Integration\ViciDialClient;

// ── 1. Bootstrap ─────────────────────────────────────────────────────────────

$config   = new Config();
$logger   = new Logger($config);
$http     = new ViciDialClient($config);
$listener = new AsteriskEventListener($config, $logger);

$crmBase      = rtrim((string) $config->get('callback_base_url', ''), '/');
$incomingPath = (string) $config->get('incoming_callback_path', 'search_income_calls');
$hangupPath   = (string) $config->get('hangup_callback_path', 'call_hangup');

$logger->info('Asterisk Integration service initialised.');
$logger->info("CRM base URL : {$crmBase}");
$logger->info("AMI host     : " . $config->get('ami_host', $config->get('server')) . ':' . $config->get('ami_port', 5038));

// ── 2. Signal handling (graceful shutdown on SIGTERM / SIGINT) ────────────────

if (function_exists('pcntl_signal')) {
    $shutdown = static function () use ($listener, $logger): void {
        $logger->info('Shutdown signal received.');
        $listener->stop();
    };
    pcntl_signal(SIGTERM, $shutdown);
    pcntl_signal(SIGINT,  $shutdown);
    pcntl_async_signals(true);
}

// ── 3. Helper: POST an event to the CRM ──────────────────────────────────────

$notify = static function (string $url, array $data) use ($http, $logger): void {
    $logger->debug("CRM notify → {$url}");
    $logger->debug('Payload: ' . json_encode($data));
    try {
        $result = $http->postJson($url, $data);
        if ($result['success']) {
            $logger->debug("CRM notify OK (HTTP {$result['status']})");
        } else {
            $logger->warning("CRM notify non-2xx (HTTP {$result['status']}) → {$url}");
        }
    } catch (\Throwable $e) {
        $logger->error("CRM notify failed: {$e->getMessage()} → {$url}");
    }
};

// ── 4. Event handlers ─────────────────────────────────────────────────────────

/**
 * NEW CALL — fired when Asterisk creates a new channel (ringing).
 * AMI event: Newchannel
 * Maps to: POST <crm>/search_income_calls/<mobile>
 */
$listener->on('Newchannel', static function (array $event) use ($notify, $logger, $crmBase, $incomingPath): void {
    $mobile = $event['CallerIDNum'] ?? $event['Exten'] ?? '';
    if ($mobile === '' || $mobile === 's') {
        return;
    }

    $channel = $event['Channel'] ?? '';
    $state   = $event['ChannelStateDesc'] ?? '';
    $logger->info("NEW CALL  mobile={$mobile}  channel={$channel}  state={$state}");

    $url = "{$crmBase}/{$incomingPath}/" . rawurlencode($mobile);
    $notify($url, [
        'event'         => 'Newchannel',
        'mobile'        => $mobile,
        'caller_id'     => $event['CallerIDName'] ?? $mobile,
        'channel'       => $channel,
        'channel_state' => $state,
        'timestamp'     => time(),
    ]);
});

/**
 * HANGUP — fired when a channel is terminated.
 * AMI event: Hangup
 * Maps to: POST <crm>/call_hangup/<mobile>
 */
$listener->on('Hangup', static function (array $event) use ($notify, $logger, $crmBase, $hangupPath): void {
    $mobile = $event['CallerIDNum'] ?? '';
    if ($mobile === '' || $mobile === 's') {
        return;
    }

    $channel  = $event['Channel']   ?? '';
    $cause    = $event['Cause']     ?? '';
    $causeTxt = $event['Cause-txt'] ?? '';
    $logger->info("HANGUP    mobile={$mobile}  channel={$channel}  cause={$cause} ({$causeTxt})");

    $url = "{$crmBase}/{$hangupPath}/" . rawurlencode($mobile);
    $notify($url, [
        'event'        => 'Hangup',
        'mobile'       => $mobile,
        'caller_id'    => $event['CallerIDName'] ?? $mobile,
        'channel'      => $channel,
        'cause'        => $cause,
        'cause_txt'    => $causeTxt,
        'timestamp'    => time(),
    ]);
});

/**
 * DIAL BEGIN — agent starts dialing the customer.
 */
$listener->on('DialBegin', static function (array $event) use ($logger): void {
    $dest = $event['DestCallerIDNum'] ?? $event['Destination'] ?? '';
    $logger->info("DIAL BEGIN  channel={$event['Channel']}  dest={$dest}");
});

/**
 * DIAL END — dial attempt result.
 */
$listener->on('DialEnd', static function (array $event) use ($logger): void {
    $status = $event['DialStatus'] ?? '';
    $logger->info("DIAL END    channel={$event['Channel']}  status={$status}");
});

/**
 * HOLD / UNHOLD — agent places call on hold or resumes it.
 */
$listener->on(['Hold', 'Unhold'], static function (array $event) use ($logger): void {
    $type = strtoupper($event['Event'] ?? '');
    $logger->info("{$type}  channel={$event['Channel']}");
});

/**
 * BRIDGE ENTER — two channels are bridged (customer & agent connected).
 */
$listener->on('BridgeEnter', static function (array $event) use ($logger): void {
    $bridge = $event['BridgeUniqueid'] ?? '';
    $logger->info("BRIDGE ENTER  channel={$event['Channel']}  bridge={$bridge}");
});

/**
 * BRIDGE LEAVE — a channel leaves the bridge.
 */
$listener->on('BridgeLeave', static function (array $event) use ($logger): void {
    $bridge = $event['BridgeUniqueid'] ?? '';
    $logger->info("BRIDGE LEAVE  channel={$event['Channel']}  bridge={$bridge}");
});

// ── 5. Start (blocks until stop() is called or the process is killed) ─────────

$listener->start();
