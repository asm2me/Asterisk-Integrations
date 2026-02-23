# Examples

Practical, copy-paste examples for every integration scenario.

---

## Table of Contents

- [ViciDial HTTP API](#vicidial-http-api)
- [Asterisk Manager Interface (AMI)](#asterisk-manager-interface-ami)
- [Background Service Event Handlers](#background-service-event-handlers)
- [CRM Inbound Callback Endpoint](#crm-inbound-callback-endpoint)
- [Config Overrides at Runtime](#config-overrides-at-runtime)

---

## ViciDial HTTP API

### Setup

```php
require_once '/var/www/html/Asterisk-Integrations/src/Config.php';
require_once '/var/www/html/Asterisk-Integrations/src/ViciDialClient.php';
require_once '/var/www/html/Asterisk-Integrations/src/AsteriskIntegration.php';

use Asterisk\Integration\AsteriskIntegration;
use Asterisk\Integration\Config;

$asterisk = new AsteriskIntegration(new Config([
    'server'   => '192.168.1.100',
    'protocol' => 'http',
    'source'   => 'Mani',
]));
```

---

### Open the agent panel in a new browser tab

```php
// Build the onload attribute
$onload = $asterisk->getAuthOnload([
    'ext'           => '8001',
    'ext_password'  => 'secret123',
    'voip_login'    => 'agent01',
    'voip_password' => 'voippass',
    'campaign'      => 'SALES',
]);

// Use it directly in HTML
echo '<body ' . $onload . '>';
// Output: <body onload="window.open('http://192.168.1.100/agc/vicidial.php?...', '_blank');">
```

---

### Place an outbound call

```php
$result = $asterisk->callClient('0501234567', 'agent01');

if ($result['success']) {
    echo 'Call initiated.';
} else {
    echo 'Failed. HTTP ' . $result['status'];
}
```

---

### Hold → resume → hang up

```php
$agent    = 'agent01';
$password = 'secret123';

// Put the active call on hold
$asterisk->holdClient($agent, $password);

// Resume it
$asterisk->unHoldClient($agent, $password);

// End the call
$asterisk->hangUpClient($agent, $password);
```

---

## Asterisk Manager Interface (AMI)

### Setup

```php
require_once '/var/www/html/Asterisk-Integrations/src/Config.php';
require_once '/var/www/html/Asterisk-Integrations/src/AsteriskManager.php';

use Asterisk\Integration\AsteriskManager;
use Asterisk\Integration\Config;

$config = new Config([
    'ami_host'     => '192.168.1.100',
    'ami_port'     => 5038,
    'ami_username' => 'manager',
    'ami_secret'   => 'manager_secret',
]);

$ami = new AsteriskManager($config);
$ami->connect();

if (!$ami->login($config->get('ami_username'), $config->get('ami_secret'))) {
    die('AMI login failed');
}
```

---

### Place an outbound call

```php
$response = $ami->originate(
    'SIP/8001',          // channel (agent's SIP extension)
    '0501234567',        // destination number
    'from-internal',     // dialplan context
    '1'                  // dialplan priority
);

echo $response['Response'];   // Success
echo $response['Message'];    // Originate successfully queued
```

### Place a call with custom CallerID

```php
$ami->originate('SIP/8001', '0501234567', 'from-internal', '1', [
    'CALLERID(name)' => 'Support',
    'CALLERID(num)'  => '920000001',
]);
```

---

### Hang up a channel

```php
$ami->hangup('SIP/8001-00000001');        // Normal clearing (cause 16)
$ami->hangup('SIP/8001-00000001', 21);    // Rejected (cause 21)
```

---

### Transfer a call

```php
// Blind transfer agent channel to extension 8002
$ami->redirect('SIP/8001-00000001', '8002', 'from-internal');
```

---

### Hold and resume via AMI Park

```php
// Park the customer leg (put on hold)
$ami->park(
    'SIP/customer-00000002',   // channel to park
    'SIP/8001-00000001'        // agent channel that initiated the park
);

// Retrieve: the agent dials the parked slot number (e.g. 701)
// to resume the call — no AMI action required
```

---

### Check channel status

```php
// Single channel
$status = $ami->channelStatus('SIP/8001-00000001');
print_r($status);

// All active channels
$all = $ami->channelStatus();
print_r($all);
```

---

### Send an arbitrary AMI action

```php
// Queue status
$response = $ami->sendAction(['Action' => 'QueueStatus', 'Queue' => 'sales']);

// Agent logon to a queue
$response = $ami->sendAction([
    'Action'    => 'QueueAdd',
    'Queue'     => 'sales',
    'Interface' => 'SIP/8001',
    'Penalty'   => '0',
    'Paused'    => 'false',
]);
```

---

### Always disconnect when done

```php
$ami->disconnect();
```

---

## Background Service Event Handlers

Add extra event handlers to `service.php` before `$listener->start()`.

### Log every AMI event (catch-all)

```php
$listener->on('*', static function (array $event) use ($logger): void {
    $logger->debug('[AMI] ' . ($event['Event'] ?? 'unknown') . ' — ' . json_encode($event));
});
```

---

### Forward answered calls to CRM

```php
$listener->on('BridgeEnter', static function (array $event) use ($notify, $crmBase): void {
    $mobile = $event['CallerIDNum'] ?? '';
    if ($mobile === '' || $mobile === 's') {
        return;
    }
    $url = "{$crmBase}/call_answered/" . rawurlencode($mobile);
    $notify($url, [
        'event'     => 'Answered',
        'mobile'    => $mobile,
        'channel'   => $event['Channel']        ?? '',
        'bridge'    => $event['BridgeUniqueid'] ?? '',
        'timestamp' => time(),
    ]);
});
```

---

### Notify CRM when an agent is assigned from a queue

```php
$listener->on('AgentConnect', static function (array $event) use ($notify, $crmBase): void {
    $url = "{$crmBase}/agent_connected/" . rawurlencode($event['CallerIDNum'] ?? '');
    $notify($url, [
        'event'      => 'AgentConnect',
        'mobile'     => $event['CallerIDNum']   ?? '',
        'agent'      => $event['MemberName']    ?? '',
        'queue'      => $event['Queue']         ?? '',
        'timestamp'  => time(),
    ]);
});
```

---

## CRM Inbound Callback Endpoint

Place this code inside the CRM route/controller that handles Asterisk callbacks.

### Incoming call endpoint — `POST /search_income_calls/{mobile}`

```php
require_once '/var/www/html/Asterisk-Integrations/src/Config.php';
require_once '/var/www/html/Asterisk-Integrations/src/CallbackHandler.php';

use Asterisk\Integration\CallbackHandler;
use Asterisk\Integration\Config;

$handler = new CallbackHandler(new Config());

// $mobile comes from your router (the URL segment)
$event = $handler->parseRequest($mobile);

// $event fields:
// 'mobile'    => '0501234567'
// 'status'    => 'RINGING'
// 'caller_id' => '0501234567'
// 'channel'   => 'SIP/8001-00000001'
// 'timestamp' => 1740355200
// 'raw'       => [...]

// Look up the client in the CRM database
// $client = Client::where('mobile', $event['mobile'])->first();

// Return 200 so Asterisk does not retry
$handler->respond();
```

---

### JSON body from the background service

When `service.php` is running, the CRM receives a JSON body instead of form fields:

```json
{
  "event":          "Newchannel",
  "mobile":         "0501234567",
  "caller_id":      "John Doe",
  "channel":        "SIP/8001-00000001",
  "channel_state":  "Ring",
  "timestamp":      1740355200
}
```

Read it in the CRM like this:

```php
$body  = file_get_contents('php://input');
$data  = json_decode($body, true);
$mobile = $data['mobile'] ?? '';
```

---

### Hangup endpoint — `POST /call_hangup/{mobile}`

```php
$event = $handler->parseHangup($mobile);

// Additional fields vs incoming call:
// 'hangup_cause' => '16'
// 'bill_seconds' => 90
// 'type'         => 'hangup'

// Update call record in CRM:
// $call = CallLog::where('mobile', $event['mobile'])->latest()->first();
// $call->update(['duration' => $event['bill_seconds'], 'status' => 'completed']);

$handler->respond();
```

---

## Config Overrides at Runtime

### Use a different server per request

```php
$asterisk = new AsteriskIntegration(new Config([
    'server' => $request->get('branch_ip'),
]));
```

### Disable SSL for internal CRM servers

```php
$config = new Config(['ssl_verify' => false]);
```

### Increase timeouts for slow networks

```php
$config = new Config([
    'timeout'         => 60,
    'connect_timeout' => 20,
]);
```

### Skip config.php entirely

```php
$config = new Config([
    'server'            => '10.0.0.5',
    'ami_host'          => '10.0.0.5',
    'ami_port'          => 5038,
    'ami_username'      => 'manager',
    'ami_secret'        => 'secret',
    'callback_base_url' => 'https://crm.example.com/public',
    'ssl_verify'        => false,
    'log_dir'           => '/var/log/asterisk-integration',
    'log_level'         => 'DEBUG',
], false);   // <-- false = do not load config.php
```
