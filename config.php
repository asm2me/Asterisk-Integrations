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

    // ── HTTP client ──────────────────────────────────────────────────────────
    // Total request timeout in seconds.
    'timeout'         => 30,

    // Connection timeout in seconds.
    'connect_timeout' => 10,

];
