<?php

/**
 * Asterisk / ViciDial integration – default configuration.
 *
 * Copy this file to config.local.php and override only the values you need,
 * or pass an array of overrides directly to the Config constructor.
 */

return [

    // ── Server ───────────────────────────────────────────────────────────────
    // IP address or hostname of the Asterisk / ViciDial server.
    'server'   => '192.168.1.100',

    // 'http' or 'https'
    'protocol' => 'http',

    // ── ViciDial API ─────────────────────────────────────────────────────────
    // The "source" parameter sent on every /connect/functions.php request.
    'source'   => 'Mani',

    // ── HTTP client (ViciDial) ────────────────────────────────────────────────
    // Total request timeout in seconds.
    'timeout'         => 30,

    // Connection timeout in seconds.
    'connect_timeout' => 10,

    // ── Asterisk Manager Interface (AMI) ──────────────────────────────────────
    // Host for the AMI socket (defaults to 'server' above if not set).
    'ami_host' => '192.168.1.100',

    // AMI TCP port (default 5038).
    'ami_port' => 5038,

    // AMI manager credentials (defined in /etc/asterisk/manager.conf).
    'ami_username' => 'manager',
    'ami_secret'   => 'manager_secret',

    // Seconds to wait before reconnecting after a dropped AMI connection.
    'ami_reconnect_delay' => 5,

    // ── Incoming call callback ────────────────────────────────────────────────
    // Base URL of the CRM that Asterisk will POST call events to.
    'callback_base_url' => 'https://172.16.0.200/jebaya/public',

    // Path segment appended to callback_base_url for new / ringing calls.
    // Full URL: <callback_base_url>/search_income_calls/<mobile>
    'incoming_callback_path' => 'search_income_calls',

    // Path segment appended to callback_base_url for hangup / call-ended events.
    // Full URL: <callback_base_url>/call_hangup/<mobile>
    'hangup_callback_path' => 'call_hangup',

    // ── Logging ───────────────────────────────────────────────────────────────
    // Directory where daily log files are written.
    // File name pattern: service-YYYY-MM-DD.log
    'log_dir' => __DIR__ . '/logs',

    // Minimum level to record: DEBUG | INFO | WARNING | ERROR
    'log_level' => 'INFO',

    // Also write every log entry to stdout (captured by systemd/journald).
    'log_to_stdout' => true,

];
