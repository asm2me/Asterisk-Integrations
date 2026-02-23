<?php

/**
 * AsteriskIntegration – standalone usage example.
 *
 * No framework required. Just load the two classes and go.
 */

require_once __DIR__ . '/src/Config.php';
require_once __DIR__ . '/src/ViciDialClient.php';
require_once __DIR__ . '/src/AsteriskIntegration.php';

use Asterisk\Integration\AsteriskIntegration;
use Asterisk\Integration\Config;

// ── 1. Load config (config.php defaults + any overrides) ─────────────────────

$config   = new Config(['server' => '192.168.1.100']);   // override just the server
$asterisk = new AsteriskIntegration($config);

// ── 2. Auth URL (opens ViciDial agent panel in a new tab) ────────────────────

$user = [
    'ext'           => '8001',
    'ext_password'  => 'secret123',
    'voip_login'    => 'agent01',
    'voip_password' => 'voippass',
    'campaign'      => 'SALES',
];

$onload = $asterisk->getAuthOnload($user);
// onload="window.open('http://192.168.1.100/agc/vicidial.php?...', '_blank');"
echo $onload . PHP_EOL;

// ── 3. Place an outbound call ────────────────────────────────────────────────

$agentUser = 'agent01';
$phone     = '0501234567';

$result = $asterisk->callClient($phone, $agentUser);
// $result['success']  – true/false
// $result['status']   – HTTP status code
// $result['response'] – raw ViciDial response body
// $result['data']     – parameters that were sent
echo 'callClient: ' . ($result['success'] ? 'OK' : 'FAILED') . PHP_EOL;

// ── 4. Hold the active call ──────────────────────────────────────────────────

$phonePass = 'secret123';

$result = $asterisk->holdClient($agentUser, $phonePass);
echo 'holdClient: ' . ($result['success'] ? 'OK' : 'FAILED') . PHP_EOL;

// ── 5. Resume (un-hold) the call ─────────────────────────────────────────────

$result = $asterisk->unHoldClient($agentUser, $phonePass);
echo 'unHoldClient: ' . ($result['success'] ? 'OK' : 'FAILED') . PHP_EOL;

// ── 6. Hang up ───────────────────────────────────────────────────────────────

$result = $asterisk->hangUpClient($agentUser, $phonePass);
echo 'hangUpClient: ' . ($result['success'] ? 'OK' : 'FAILED') . PHP_EOL;
