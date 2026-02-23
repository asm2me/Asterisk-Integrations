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
require_once __DIR__ . '/src/ViciDialClient.php';
require_once __DIR__ . '/src/AsteriskEventListener.php';

use Asterisk\Integration\AsteriskEventListener;
use Asterisk\Integration\Config;
use Asterisk\Integration\ViciDialClient;

// ── 1. Bootstrap ─────────────────────────────────────────────────────────────

$config   = new Config();                  // reads config.php
$http     = new ViciDialClient($config);   // reused for CRM notifications
$listener = new AsteriskEventListener($config);

$crmBase     = rtrim((string) $config->get('callback_base_url', ''), '/');
$incomingPath = (string) $config->get('incoming_callback_path', 'search_income_calls');
$hangupPath   = (string) $config->get('hangup_callback_path', 'call_hangup');

// ── 2. Signal handling (graceful shutdown on SIGTERM / SIGINT) ────────────────

if (function_exists('pcntl_signal')) {
    $shutdown = static function () use ($listener): void {
        $listener->stop();
    };
    pcntl_signal(SIGTERM, $shutdown);
    pcntl_signal(SIGINT,  $shutdown);
    pcntl_async_signals(true);
}

// ── 3. Helper: POST an event to the CRM ──────────────────────────────────────

$notify = static function (string $url, array $data) use ($http): void {
    try {
        $http->post($url, $data);
    } catch (\Throwable $e) {
        fwrite(STDERR, '[WARN] CRM notify failed: ' . $e->getMessage() . PHP_EOL);
    }
};

// ── 4. Event handlers ─────────────────────────────────────────────────────────

/**
 * NEW CALL — fired when Asterisk creates a new channel (ringing).
 * AMI event: Newchannel
 * Maps to: POST <crm>/search_income_calls/<mobile>
 */
$listener->on('Newchannel', static function (array $event) use ($notify, $crmBase, $incomingPath): void {
    $mobile = $event['CallerIDNum'] ?? $event['Exten'] ?? '';
    if ($mobile === '' || $mobile === 's') {
        return;   // skip internal / unknown channels
    }

    $url = "{$crmBase}/{$incomingPath}/" . rawurlencode($mobile);

    $notify($url, [
        'event'      => 'Newchannel',
        'mobile'     => $mobile,
        'caller_id'  => $event['CallerIDName'] ?? $mobile,
        'channel'    => $event['Channel']      ?? '',
        'channel_state' => $event['ChannelStateDesc'] ?? '',
        'timestamp'  => time(),
    ]);
});

/**
 * HANGUP — fired when a channel is terminated.
 * AMI event: Hangup
 * Maps to: POST <crm>/call_hangup/<mobile>
 */
$listener->on('Hangup', static function (array $event) use ($notify, $crmBase, $hangupPath): void {
    $mobile = $event['CallerIDNum'] ?? '';
    if ($mobile === '' || $mobile === 's') {
        return;
    }

    $url = "{$crmBase}/{$hangupPath}/" . rawurlencode($mobile);

    $notify($url, [
        'event'        => 'Hangup',
        'mobile'       => $mobile,
        'caller_id'    => $event['CallerIDName']  ?? $mobile,
        'channel'      => $event['Channel']       ?? '',
        'cause'        => $event['Cause']         ?? '',
        'cause_txt'    => $event['Cause-txt']     ?? '',
        'timestamp'    => time(),
    ]);
});

/**
 * DIAL BEGIN — agent starts dialing the customer.
 * AMI event: DialBegin
 */
$listener->on('DialBegin', static function (array $event): void {
    $dest = $event['DestCallerIDNum'] ?? $event['Destination'] ?? '';
    fwrite(STDOUT, "[dial_begin] channel={$event['Channel']} dest={$dest}" . PHP_EOL);
});

/**
 * DIAL END — dial attempt result.
 * AMI event: DialEnd
 */
$listener->on('DialEnd', static function (array $event): void {
    fwrite(STDOUT, "[dial_end] channel={$event['Channel']} dialstatus={$event['DialStatus']}" . PHP_EOL);
});

/**
 * HOLD / UNHOLD — agent places call on hold or resumes it.
 */
$listener->on(['Hold', 'Unhold'], static function (array $event): void {
    $type = strtolower($event['Event']);
    fwrite(STDOUT, "[{$type}] channel={$event['Channel']}" . PHP_EOL);
});

/**
 * BRIDGE ENTER — two channels are bridged (customer & agent connected).
 * AMI event: BridgeEnter
 */
$listener->on('BridgeEnter', static function (array $event): void {
    fwrite(STDOUT, "[bridge_enter] channel={$event['Channel']} bridge={$event['BridgeUniqueid']}" . PHP_EOL);
});

/**
 * BRIDGE LEAVE — a channel leaves the bridge (one party hung up).
 */
$listener->on('BridgeLeave', static function (array $event): void {
    fwrite(STDOUT, "[bridge_leave] channel={$event['Channel']} bridge={$event['BridgeUniqueid']}" . PHP_EOL);
});

// ── 5. Start (blocks until stop() is called or the process is killed) ─────────

$listener->start();
