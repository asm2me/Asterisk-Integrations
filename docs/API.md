# API Reference

Complete reference for every public class and method in the Asterisk Integration module.

---

## Table of Contents

- [Config](#config)
- [Logger](#logger)
- [ViciDialClient](#vicidialclient)
- [AsteriskIntegration](#asteriskintegration)
- [AsteriskManager](#asteriskmanager)
- [AsteriskEventListener](#asteriskeventlistener)
- [CallbackHandler](#callbackhandler)
- [CRM Callback Payloads](#crm-callback-payloads)

---

## Config

**File:** `src/Config.php`
**Namespace:** `Asterisk\Integration`

Loads `config.php` and provides key-based access to all settings.

### Constructor

```php
new Config(array $overrides = [], bool $loadDefaults = true)
```

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$overrides` | `array` | `[]` | Key/value pairs that take precedence over `config.php` |
| `$loadDefaults` | `bool` | `true` | When `false`, only `$overrides` are used (no file read) |

### Methods

#### `get(string $key, $default = null)`

Returns the value for `$key`, or `$default` if the key is not set.

```php
$config->get('server');               // '192.168.1.100'
$config->get('ami_port', 5038);       // 5038 (default)
```

#### `all(): array`

Returns all resolved key/value pairs.

---

## Logger

**File:** `src/Logger.php`
**Namespace:** `Asterisk\Integration`

Writes daily log files to `logs/service-YYYY-MM-DD.log` and optionally mirrors to stdout.

### Log level constants

| Constant | Value | Severity |
|---|---|---|
| `Logger::DEBUG` | `'DEBUG'` | Lowest — verbose tracing |
| `Logger::INFO` | `'INFO'` | Normal operational messages |
| `Logger::WARNING` | `'WARNING'` | Unexpected but recoverable situations |
| `Logger::ERROR` | `'ERROR'` | Errors that need attention |

### Constructor

```php
new Logger(?Config $config = null)
```

Config keys read:

| Key | Default | Description |
|---|---|---|
| `log_dir` | `<project>/logs` | Directory for log files |
| `log_level` | `'INFO'` | Minimum level to record |
| `log_to_stdout` | `true` | Also write to stdout/stderr |

### Methods

```php
$logger->debug(string $message): void
$logger->info(string $message): void
$logger->warning(string $message): void
$logger->error(string $message): void
$logger->log(string $level, string $message): void   // arbitrary level
```

### Log line format

```
[2026-02-24 14:05:30] [INFO   ] Connected and authenticated to 192.168.1.100:5038
[2026-02-24 14:05:45] [WARNING] CRM notify non-2xx (HTTP 404) → https://...
[2026-02-24 14:06:00] [ERROR  ] cURL error (7): Failed to connect
```

---

## ViciDialClient

**File:** `src/ViciDialClient.php`
**Namespace:** `Asterisk\Integration`

HTTP client for making cURL POST requests to ViciDial and CRM endpoints.

### Constructor

```php
new ViciDialClient(?Config $config = null)
```

Config keys read:

| Key | Default | Description |
|---|---|---|
| `timeout` | `30` | Total request timeout (seconds) |
| `connect_timeout` | `10` | TCP connection timeout (seconds) |
| `ssl_verify` | `true` | Verify SSL certificates (`false` for self-signed certs) |

### Methods

#### `post(string $url, array $data): array`

Sends `$data` wrapped in `{"user": {...}}` — required by the ViciDial API.

```php
$result = $client->post($url, ['server' => '...', 'phone' => '...']);
```

#### `postJson(string $url, array $data): array`

Sends `$data` directly as a flat JSON object — for CRM callback endpoints.

```php
$result = $client->postJson($url, [
    'event'     => 'Newchannel',
    'mobile'    => '0501234567',
    'timestamp' => time(),
]);
```

#### Return value (both methods)

```php
[
    'success'  => true,     // true if HTTP 2xx
    'status'   => 200,      // HTTP status code
    'response' => '...',    // raw response body
    'data'     => [...],    // the array that was sent
]
```

> Throws `\RuntimeException` on cURL transport errors (network failure, DNS, etc.).

---

## AsteriskIntegration

**File:** `src/AsteriskIntegration.php`
**Namespace:** `Asterisk\Integration`

High-level ViciDial HTTP API wrapper. Handles agent login URLs and call control actions.

### Constructor

```php
new AsteriskIntegration(?Config $config = null, ?ViciDialClient $client = null)
```

Config keys read: `server`, `protocol`, `source`.

### Methods

#### `getAuthUrl(array $user): string`

Returns the full ViciDial agent-login URL.

```php
$url = $asterisk->getAuthUrl([
    'ext'           => '8001',
    'ext_password'  => 'secret',
    'voip_login'    => 'agent01',
    'voip_password' => 'voippass',
    'campaign'      => 'SALES',
]);
// http://192.168.1.100/agc/vicidial.php?phone_login=8001&phone_pass=secret&...
```

#### `getAuthOnload(array $user): string`

Returns an HTML `onload` attribute string for opening the agent panel in a new tab.

```php
echo '<body ' . $asterisk->getAuthOnload($user) . '>';
// <body onload="window.open('http://...', '_blank');">
```

#### `callClient(string $phone, string $agentUser): array`

Places an outbound call. Maps to `function=external_dial`.

```php
$result = $asterisk->callClient('0501234567', 'agent01');
```

#### `holdClient(string $agentUser, string $phonePass): array`

Parks the active call. Maps to `function=park_call&value=PARK_CUSTOMER`.

```php
$result = $asterisk->holdClient('agent01', 'ext_password');
```

#### `unHoldClient(string $agentUser, string $phonePass): array`

Resumes a parked call. Maps to `function=park_call&value=GRAB_CUSTOMER`.

```php
$result = $asterisk->unHoldClient('agent01', 'ext_password');
```

#### `hangUpClient(string $agentUser, string $phonePass): array`

Hangs up the active call. Maps to `function=external_hangup&value=1`.

```php
$result = $asterisk->hangUpClient('agent01', 'ext_password');
```

---

## AsteriskManager

**File:** `src/AsteriskManager.php`
**Namespace:** `Asterisk\Integration`

Direct AMI TCP socket client for single action/response interactions.

### Constructor

```php
new AsteriskManager(?Config $config = null)
```

Config keys read: `ami_host` (falls back to `server`), `ami_port`, `timeout`.

### Connection methods

| Method | Description |
|---|---|
| `connect(): void` | Open TCP socket and consume greeting |
| `login(string $username, string $secret): bool` | Authenticate; returns `true` on success |
| `disconnect(): void` | Send `Logoff` and close socket |
| `isConnected(): bool` | Check if socket is open |

### Call action methods

#### `originate(string $channel, string $extension, string $context, string $priority, array $variables): array`

Place an outbound call asynchronously.

```php
$ami->originate('SIP/8001', '0501234567');
$ami->originate('SIP/8001', '0501234567', 'from-internal', '1', ['CALLERID(name)' => 'Office']);
```

#### `hangup(string $channel, int $cause = 16): array`

Hang up a channel. Cause `16` = Normal call clearing.

```php
$ami->hangup('SIP/8001-00000001');
$ami->hangup('SIP/8001-00000001', 21);   // 21 = Call rejected
```

#### `redirect(string $channel, string $extension, string $context, string $priority): array`

Blind-transfer a channel to a new extension.

```php
$ami->redirect('SIP/8001-00000001', '8002', 'default');
```

#### `park(string $channel, string $parkChannel, string $parkingLot = 'default'): array`

Park the customer channel.

```php
$ami->park('SIP/customer-00000002', 'SIP/8001-00000001');
```

#### `channelStatus(string $channel = ''): array`

Get channel status. Pass empty string to get all channels.

```php
$ami->channelStatus('SIP/8001-00000001');
$ami->channelStatus();    // all channels
```

#### `listChannels(): array`

List all active channels (`CoreShowChannels` action).

#### `sendAction(array $action): array`

Send any arbitrary AMI action and return the response packet.

```php
$response = $ami->sendAction(['Action' => 'QueueStatus', 'Queue' => 'sales']);
```

### AMI response format

All action methods return an associative array of the AMI response packet:

```php
[
    'Response' => 'Success',
    'Message'  => 'Originate successfully queued',
    'ActionID' => 'ami-1-1740355200',
]
```

---

## AsteriskEventListener

**File:** `src/AsteriskEventListener.php`
**Namespace:** `Asterisk\Integration`

Persistent AMI event listener. Designed to run as a background daemon.

### Constructor

```php
new AsteriskEventListener(?Config $config = null, ?Logger $logger = null)
```

Config keys read: `ami_host`, `ami_port`, `timeout`, `ami_reconnect_delay`, `ami_username`, `ami_secret`.

### Methods

#### `on(string|array $events, callable $handler): self`

Register a handler for one or more event types. Use `'*'` to catch all events.

```php
$listener->on('Newchannel', function (array $event): void { ... });
$listener->on(['Hold', 'Unhold'], function (array $event): void { ... });
$listener->on('*', function (array $event): void { ... });   // catch-all
```

The `$event` array contains every field from the AMI packet:

```php
[
    'Event'           => 'Newchannel',
    'Channel'         => 'SIP/8001-00000001',
    'CallerIDNum'     => '0501234567',
    'CallerIDName'    => 'John Doe',
    'ChannelStateDesc'=> 'Ring',
    'Uniqueid'        => '1740355200.1',
    // ...
]
```

#### `start(): void`

**Blocking.** Connects, logs in, and runs the event loop. Reconnects automatically on connection loss.

#### `stop(): void`

Signal the listener to stop after the next read timeout (~5 s).

#### `connect(): void` / `login(): bool` / `isConnected(): bool`

Manual connection control (used internally; exposed for testing).

#### `getLogger(): Logger`

Returns the Logger instance shared with the listener.

### AMI events reference

| Event | Fired when |
|---|---|
| `Newchannel` | A new channel is created (call ringing) |
| `Hangup` | A channel is terminated |
| `DialBegin` | An agent starts dialing |
| `DialEnd` | A dial attempt completes |
| `Hold` | A call is placed on hold |
| `Unhold` | A held call is resumed |
| `BridgeEnter` | Two channels are bridged (call answered) |
| `BridgeLeave` | A channel leaves a bridge |
| `AgentCalled` | A queue agent is rung |
| `AgentConnect` | A queue agent answers |
| `AgentComplete` | A queue call completes |

---

## CallbackHandler

**File:** `src/CallbackHandler.php`
**Namespace:** `Asterisk\Integration`

Parses inbound HTTP callbacks from Asterisk and builds response/URL helpers.

### Constructor

```php
new CallbackHandler(?Config $config = null)
```

Config keys read: `callback_base_url`, `hangup_callback_path`.

### Status constants

```php
CallbackHandler::STATUS_RINGING    // 'RINGING'
CallbackHandler::STATUS_ANSWERED   // 'ANSWERED'
CallbackHandler::STATUS_BUSY       // 'BUSY'
CallbackHandler::STATUS_NO_ANSWER  // 'NO ANSWER'
CallbackHandler::STATUS_FAILED     // 'FAILED'
CallbackHandler::STATUS_HANGUP     // 'HANGUP'
```

### Methods

#### `parseRequest(?string $mobileFromUrl = null): array`

Parse an incoming-call callback. Returns:

```php
[
    'mobile'    => '0501234567',   // sanitised caller number
    'status'    => 'RINGING',      // call status
    'caller_id' => '0501234567',   // CallerID
    'channel'   => 'SIP/...',      // Asterisk channel
    'duration'  => 0,              // seconds
    'timestamp' => 1740355200,     // Unix timestamp
    'raw'       => [...],          // full raw payload
]
```

#### `parseHangup(?string $mobileFromUrl = null): array`

Parse a hangup callback. Returns the same structure as `parseRequest()` plus:

```php
[
    // ... all parseRequest() fields ...
    'hangup_cause' => '16',        // Asterisk hangup cause
    'bill_seconds' => 90,          // billable seconds
    'type'         => 'hangup',
]
```

#### `buildCallbackUrl(string $mobile): string`

```php
$url = $handler->buildCallbackUrl('0501234567');
// https://172.16.0.200/jebaya/public/search_income_calls/0501234567
```

#### `buildHangupUrl(string $mobile): string`

```php
$url = $handler->buildHangupUrl('0501234567');
// https://172.16.0.200/jebaya/public/call_hangup/0501234567
```

#### `buildDialplanCurlLine(string $mobile = '${CALLERID(num)}'): string`

Generates the `extensions.conf` line to fire the incoming-call callback.

#### `buildDialplanHangupCurlLine(string $mobile = '${CALLERID(num)}'): string`

Generates the `extensions.conf` `h`-extension line to fire the hangup callback.

#### `respond(string $body = 'OK'): void`

Send a `200 OK` plain-text response back to Asterisk.

---

## CRM Callback Payloads

The background service (`service.php`) POSTs these JSON bodies to the CRM.

### New / ringing call

**URL:** `POST https://<crm>/search_income_calls/{mobile}`

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

### Call hangup

**URL:** `POST https://<crm>/call_hangup/{mobile}`

```json
{
  "event":      "Hangup",
  "mobile":     "0501234567",
  "caller_id":  "John Doe",
  "channel":    "SIP/8001-00000001",
  "cause":      "16",
  "cause_txt":  "Normal Clearing",
  "timestamp":  1740355260
}
```

### Common Hangup cause codes

| Code | Meaning |
|---|---|
| `16` | Normal call clearing |
| `17` | User busy |
| `18` | No user responding |
| `19` | No answer from user |
| `21` | Call rejected |
| `34` | No circuit available |
