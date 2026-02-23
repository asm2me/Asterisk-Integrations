<?php

/**
 * AsteriskIntegration – usage example.
 *
 * In a Laravel project:
 *   require_once __DIR__ . '/vendor/autoload.php';
 *
 * Or, if you embed this module inside an existing project with Composer,
 * just ensure the PSR-4 autoloader picks up Asterisk\Integration\*.
 */

require_once __DIR__ . '/vendor/autoload.php';

use Asterisk\Integration\AsteriskIntegration;

// ── 1. Initialise with the branch server IP ──────────────────────────────────

$server = '192.168.1.100';           // equivalent to Auth::user()->branch->branch_ip
$asterisk = new AsteriskIntegration($server);

// ── 2. Auth URL (opens ViciDial agent panel in a new tab) ────────────────────

// User credentials – in Laravel these come from Auth::user()
$user = [
    'ext'           => '8001',
    'ext_password'  => 'secret123',
    'voip_login'    => 'agent01',
    'voip_password' => 'voippass',
    'campaign'      => 'SALES',
];

$onload = $asterisk->getAuthOnload($user);
// Output:  onload="window.open('http://192.168.1.100/agc/vicidial.php?...', '_blank');"
echo $onload . PHP_EOL;

// ── 3. Place an outbound call ────────────────────────────────────────────────

$agentUser = 'agent01';
$phone     = '0501234567';

$result = $asterisk->callClient($phone, $agentUser);
// $result['success']  – true/false
// $result['status']   – HTTP status code
// $result['response'] – raw response body from ViciDial
// $result['data']     – array of parameters sent
echo 'callClient: ' . ($result['success'] ? 'OK' : 'FAILED') . PHP_EOL;

// ── 4. Hold the active call ──────────────────────────────────────────────────

$phonePass = 'secret123';     // Auth::user()->ext_password

$result = $asterisk->holdClient($agentUser, $phonePass);
echo 'holdClient: ' . ($result['success'] ? 'OK' : 'FAILED') . PHP_EOL;

// ── 5. Resume (un-hold) the call ─────────────────────────────────────────────

$result = $asterisk->unHoldClient($agentUser, $phonePass);
echo 'unHoldClient: ' . ($result['success'] ? 'OK' : 'FAILED') . PHP_EOL;

// ── 6. Hang up ───────────────────────────────────────────────────────────────

$result = $asterisk->hangUpClient($agentUser, $phonePass);
echo 'hangUpClient: ' . ($result['success'] ? 'OK' : 'FAILED') . PHP_EOL;
