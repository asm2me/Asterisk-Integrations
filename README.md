# Asterisk Integrations

A standalone PHP module for integrating with **Asterisk / ViciDial** telephony servers. No framework required — drop the `src/` folder into any PHP project and go.

---

## Features

- **ViciDial HTTP API** — agent login, outbound dial, hold, un-hold, hang-up
- **Asterisk Manager Interface (AMI)** — native TCP socket client for direct Asterisk control
- **Background event service** — persistent daemon that watches every AMI event and forwards them to the CRM in real time
- **Inbound call callbacks** — parse and respond to new-call and hang-up events sent by Asterisk to your CRM
- **Config-driven** — single `config.php` file controls every endpoint, credential, and timeout
- **Zero dependencies** — requires only PHP ≥ 8.0 with `ext-curl` and `ext-json`

---

## Requirements

| Requirement | Version |
|---|---|
| PHP | ≥ 8.0 |
| ext-curl | any |
| ext-json | any |
| Asterisk | 13 – 21 |
| ViciDial | 2.14+ |

---

## Project Structure

```
Asterisk-Integrations/
├── src/
│   ├── Config.php                  # Loads config.php and provides get()
│   ├── ViciDialClient.php          # cURL HTTP client for ViciDial API calls
│   ├── AsteriskIntegration.php     # ViciDial high-level actions (call, hold, hang-up)
│   ├── AsteriskManager.php         # AMI TCP socket client (single action/response)
│   ├── AsteriskEventListener.php   # Persistent AMI event listener (daemon)
│   └── CallbackHandler.php         # Inbound call & hangup event parser
├── service.php                     # Background service entry point
├── asterisk-integration.service    # systemd unit file
├── config.php                      # All configuration defaults
├── example.php                     # ViciDial usage example
├── example_ami.php                 # AMI usage example
└── composer.json
```

---

## Installation

### Without Composer

Copy the `src/` directory and `config.php` into your project, then `require_once` what you need:

```php
require_once '/path/to/src/Config.php';
require_once '/path/to/src/ViciDialClient.php';
require_once '/path/to/src/AsteriskIntegration.php';
// and/or
require_once '/path/to/src/AsteriskManager.php';
require_once '/path/to/src/CallbackHandler.php';
```

### With Composer

```bash
composer install
```

The `Asterisk\Integration\` namespace is auto-loaded via PSR-4.

---

## Configuration

Edit `config.php` to match your environment:

```php
return [
    // Asterisk / ViciDial server
    'server'   => '192.168.1.100',
    'protocol' => 'http',           // 'http' or 'https'

    // ViciDial API
    'source'          => 'Mani',
    'timeout'         => 30,        // seconds
    'connect_timeout' => 10,

    // Asterisk Manager Interface (AMI)
    'ami_host'     => '192.168.1.100',
    'ami_port'     => 5038,
    'ami_username' => 'manager',
    'ami_secret'   => 'manager_secret',

    // CRM callback URLs
    'callback_base_url'      => 'https://your-crm.example.com/public',
    'incoming_callback_path' => 'search_income_calls',
    'hangup_callback_path'   => 'call_hangup',
];
```

You can override individual keys at runtime without touching the file:

```php
$config = new Config(['server' => '10.0.0.5', 'protocol' => 'https']);
```

---

## ViciDial HTTP API

`AsteriskIntegration` wraps the ViciDial `/connect/functions.php` HTTP API.

### Quick start

```php
use Asterisk\Integration\AsteriskIntegration;
use Asterisk\Integration\Config;

$asterisk = new AsteriskIntegration(new Config(['server' => '192.168.1.100']));
```

### Agent login URL

```php
$onload = $asterisk->getAuthOnload([
    'ext'           => '8001',
    'ext_password'  => 'secret',
    'voip_login'    => 'agent01',
    'voip_password' => 'voippass',
    'campaign'      => 'SALES',
]);
// Returns: onload="window.open('http://192.168.1.100/agc/vicidial.php?...', '_blank');"
```

### Call actions

```php
// Place an outbound call
$result = $asterisk->callClient('0501234567', 'agent01');

// Hold the active call
$result = $asterisk->holdClient('agent01', 'ext_password');

// Resume a held call
$result = $asterisk->unHoldClient('agent01', 'ext_password');

// Hang up
$result = $asterisk->hangUpClient('agent01', 'ext_password');
```

All call-action methods return:

```php
[
    'success'  => true,           // HTTP 2xx received
    'status'   => 200,            // HTTP status code
    'response' => '...',          // raw ViciDial response body
    'data'     => [ ... ],        // parameters sent
]
```

---

## Asterisk Manager Interface (AMI)

`AsteriskManager` communicates with Asterisk directly over a TCP socket on port **5038** using the AMI text protocol.

### Quick start

```php
use Asterisk\Integration\AsteriskManager;
use Asterisk\Integration\Config;

$config = new Config([
    'ami_host'     => '192.168.1.100',
    'ami_username' => 'manager',
    'ami_secret'   => 'manager_secret',
]);

$ami = new AsteriskManager($config);
$ami->connect();
$ami->login($config->get('ami_username'), $config->get('ami_secret'));
```

### Available actions

```php
// Originate (place) an outbound call
$ami->originate('SIP/8001', '0501234567');
$ami->originate('SIP/8001', '0501234567', 'default', '1', ['CALLERID' => '12345']);

// Hang up a channel
$ami->hangup('SIP/8001-00000001');
$ami->hangup('SIP/8001-00000001', 16);   // cause 16 = Normal call clearing

// Blind transfer a channel
$ami->redirect('SIP/8001-00000001', '8002', 'default');

// Park a call (hold)
$ami->park('SIP/customer-00000002', 'SIP/8001-00000001');

// Channel status
$ami->channelStatus('SIP/8001-00000001');  // specific channel
$ami->channelStatus();                      // all channels

// List all active channels
$ami->listChannels();

// Send any arbitrary AMI action
$ami->sendAction(['Action' => 'QueueStatus', 'Queue' => 'sales']);
```

All methods return an associative array of the AMI response packet, e.g.:

```php
['Response' => 'Success', 'Message' => 'Originate successfully queued', 'ActionID' => 'ami-1-...']
```

### Disconnect

```php
$ami->disconnect();
```

---

## Inbound Call Callbacks

Asterisk notifies the CRM about new calls and hang-ups by POSTing to two URLs:

| Event | URL |
|---|---|
| New / ringing call | `https://<crm>/search_income_calls/{mobile}` |
| Call ended / hang-up | `https://<crm>/call_hangup/{mobile}` |

### Parsing a new-call event

```php
use Asterisk\Integration\CallbackHandler;
use Asterisk\Integration\Config;

$handler = new CallbackHandler(new Config());

// Pass the mobile number extracted from the URL by your router
$event = $handler->parseRequest($mobileFromUrl);

// $event keys:
// 'mobile'    – sanitised caller number
// 'status'    – RINGING | ANSWERED | BUSY | NO ANSWER | FAILED
// 'caller_id' – CallerID from Asterisk
// 'channel'   – Asterisk channel name
// 'duration'  – call duration in seconds
// 'timestamp' – Unix timestamp
// 'raw'       – full raw payload

$handler->respond();   // send 200 OK back to Asterisk
```

### Parsing a hang-up event

```php
$event = $handler->parseHangup($mobileFromUrl);

// Additional keys vs parseRequest():
// 'hangup_cause' – Asterisk hangup cause string
// 'bill_seconds' – billable seconds
// 'type'         – always 'hangup'

$handler->respond();
```

### Status constants

```php
CallbackHandler::STATUS_RINGING    // 'RINGING'
CallbackHandler::STATUS_ANSWERED   // 'ANSWERED'
CallbackHandler::STATUS_BUSY       // 'BUSY'
CallbackHandler::STATUS_NO_ANSWER  // 'NO ANSWER'
CallbackHandler::STATUS_FAILED     // 'FAILED'
CallbackHandler::STATUS_HANGUP     // 'HANGUP'
```

---

## Asterisk Dialplan Wiring

Add the following to your `extensions.conf` to fire the CRM callbacks automatically.

```ini
[incoming]
exten => _X.,1,NoOp(Incoming call from ${CALLERID(num)})
same  => n,System(curl -s -X POST \
         "https://your-crm.example.com/public/search_income_calls/${CALLERID(num)}" \
         -d "callerid=${CALLERID(num)}&status=${DIALSTATUS}&channel=${CHANNEL}&timestamp=$(date +%s)")
same  => n,Dial(SIP/${EXTEN},30)
same  => n,Hangup()

; h-extension fires on every call end
exten => h,1,NoOp(Call ended - ${CALLERID(num)})
same  => n,System(curl -s -X POST \
         "https://your-crm.example.com/public/call_hangup/${CALLERID(num)}" \
         -d "callerid=${CALLERID(num)}&status=HANGUP&hangupcause=${HANGUPCAUSE}\
             &channel=${CHANNEL}&duration=${CDR(duration)}&billseconds=${CDR(billseconds)}\
             &timestamp=$(date +%s)")
```

You can also generate these lines programmatically:

```php
$handler = new CallbackHandler(new Config());

echo $handler->buildDialplanCurlLine();       // incoming-call line
echo $handler->buildDialplanHangupCurlLine(); // hangup line
```

---

## Background Service

`service.php` is a long-running daemon that opens a **persistent AMI connection** and
listens for every Asterisk event in real time — no dialplan curl lines needed.

### Events watched

| AMI Event | Action |
|---|---|
| `Newchannel` | POST to `search_income_calls/{mobile}` |
| `Hangup` | POST to `call_hangup/{mobile}` |
| `DialBegin` / `DialEnd` | logged to stdout |
| `Hold` / `Unhold` | logged to stdout |
| `BridgeEnter` / `BridgeLeave` | logged to stdout |

### Run manually (test)

```bash
php service.php
```

---

### Install as a systemd service (Linux)

> Run all commands as **root** or with `sudo`.

**Step 1 — Deploy the project**

```bash
# Copy project to the server
cp -r /path/to/Asterisk-Integrations /var/www/asterisk-integrations

# Set ownership
chown -R www-data:www-data /var/www/asterisk-integrations
```

**Step 2 — Edit config.php**

```bash
nano /var/www/asterisk-integrations/config.php
```

Set your real values:

```php
'server'            => '192.168.1.100',   // Asterisk server IP
'ami_username'      => 'manager',
'ami_secret'        => 'your_secret',
'callback_base_url' => 'https://172.16.0.200/jebaya/public',
```

**Step 3 — Install the unit file**

```bash
cp /var/www/asterisk-integrations/asterisk-integration.service \
   /etc/systemd/system/asterisk-integration.service
```

**Step 4 — Enable and start**

```bash
# Reload systemd so it sees the new unit
systemctl daemon-reload

# Start automatically on boot
systemctl enable asterisk-integration

# Start now
systemctl start asterisk-integration
```

**Step 5 — Verify**

```bash
# Check current status
systemctl status asterisk-integration

# Watch live logs
journalctl -u asterisk-integration -f
```

### Service management commands

```bash
systemctl start   asterisk-integration   # start
systemctl stop    asterisk-integration   # stop (graceful)
systemctl restart asterisk-integration   # restart
systemctl status  asterisk-integration   # show status + last 10 log lines
journalctl -u asterisk-integration -f    # tail logs live
journalctl -u asterisk-integration --since "1 hour ago"   # last hour
```

### extensions.conf changes

With the background service running, the dialplan no longer needs the `System(curl …)` lines.
You can simplify your `[incoming]` context to:

```ini
[incoming]
exten => _X.,1,NoOp(Incoming call ${CALLERID(num)})
same  => n,Dial(SIP/${EXTEN},30)
same  => n,Hangup()
```

The service receives `Newchannel` and `Hangup` events directly from AMI — no dialplan changes required.

---

## AMI Configuration (manager.conf)

Create a manager user on the Asterisk server at `/etc/asterisk/manager.conf`:

```ini
[general]
enabled  = yes
port     = 5038
bindaddr = 0.0.0.0

[manager]
secret   = manager_secret
deny     = 0.0.0.0/0.0.0.0
permit   = 192.168.1.0/255.255.255.0   ; restrict to your app server
read     = all
write    = all
```

Reload after changes:

```bash
asterisk -rx "manager reload"
```

---

## License

MIT
