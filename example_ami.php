<?php

/**
 * AsteriskManager – standalone AMI usage example.
 *
 * Communicates directly with Asterisk over the TCP Manager Interface (port 5038).
 * No HTTP / ViciDial involved.
 */

require_once __DIR__ . '/src/Config.php';
require_once __DIR__ . '/src/AsteriskManager.php';

use Asterisk\Integration\AsteriskManager;
use Asterisk\Integration\Config;

// ── 1. Config ─────────────────────────────────────────────────────────────────

$config = new Config([
    'ami_host'     => '192.168.1.100',
    'ami_port'     => 5038,
    'ami_username' => 'manager',
    'ami_secret'   => 'manager_secret',
]);

$ami = new AsteriskManager($config);

// ── 2. Connect & login ────────────────────────────────────────────────────────

$ami->connect();

if (!$ami->login($config->get('ami_username'), $config->get('ami_secret'))) {
    die('AMI login failed.' . PHP_EOL);
}
echo 'AMI login OK' . PHP_EOL;

// ── 3. Originate an outbound call ─────────────────────────────────────────────
//    Dials extension 0501234567 from agent channel SIP/8001.

$result = $ami->originate(
    channel:   'SIP/8001',
    extension: '0501234567',
    context:   'default',
    priority:  '1'
);
echo 'originate: ' . ($result['Response'] ?? 'no response') . PHP_EOL;

// ── 4. Hang up a channel ──────────────────────────────────────────────────────

$result = $ami->hangup('SIP/8001-00000001');
echo 'hangup: ' . ($result['Response'] ?? 'no response') . PHP_EOL;

// ── 5. Park a call (hold) ─────────────────────────────────────────────────────

$result = $ami->park(
    channel:     'SIP/customer-00000002',
    parkChannel: 'SIP/8001-00000001'
);
echo 'park: ' . ($result['Response'] ?? 'no response') . PHP_EOL;

// ── 6. Redirect (transfer) a channel ─────────────────────────────────────────

$result = $ami->redirect(
    channel:   'SIP/8001-00000001',
    extension: '8002',
    context:   'default'
);
echo 'redirect: ' . ($result['Response'] ?? 'no response') . PHP_EOL;

// ── 7. Channel status ─────────────────────────────────────────────────────────

$result = $ami->channelStatus('SIP/8001-00000001');
echo 'channelStatus: ' . print_r($result, true) . PHP_EOL;

// ── 8. Disconnect ─────────────────────────────────────────────────────────────

$ami->disconnect();
echo 'Disconnected.' . PHP_EOL;
