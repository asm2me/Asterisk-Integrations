<?php

namespace Asterisk\Integration;

/**
 * Handles inbound HTTP callbacks sent by Asterisk (via AGI / call-file / curl)
 * to notify the CRM about new calls, call-status changes, and hangups.
 *
 * Expected callback URL patterns:
 *   POST  https://<crm>/search_income_calls/{mobile_number}   – new / ringing call
 *   POST  https://<crm>/call_hangup/{mobile_number}           – call ended / hung up
 *
 * Asterisk dials these endpoints for every relevant call event. The handler
 * parses the payload, validates it, and returns a structured event array
 * that the CRM can act on.
 */
class CallbackHandler
{
    /** Recognised call-status values from Asterisk. */
    public const STATUS_RINGING   = 'RINGING';
    public const STATUS_ANSWERED  = 'ANSWERED';
    public const STATUS_BUSY      = 'BUSY';
    public const STATUS_NO_ANSWER = 'NO ANSWER';
    public const STATUS_FAILED    = 'FAILED';
    public const STATUS_HANGUP    = 'HANGUP';

    private string $callbackBaseUrl;
    private string $hangupCallbackPath;

    public function __construct(?Config $config = null)
    {
        $config                    = $config ?? new Config();
        $this->callbackBaseUrl     = (string) $config->get('callback_base_url', '');
        $this->hangupCallbackPath  = (string) $config->get('hangup_callback_path', 'call_hangup');
    }

    // -------------------------------------------------------------------------
    // Incoming request parsing
    // -------------------------------------------------------------------------

    /**
     * Parse the inbound callback from Asterisk.
     *
     * Call this at the top of your CRM endpoint:
     *
     *   $event = $handler->parseRequest();
     *   // $event['mobile']    – caller's mobile number (from the URL segment)
     *   // $event['status']    – call status string
     *   // $event['caller_id'] – CallerID sent by Asterisk
     *   // $event['channel']   – Asterisk channel name
     *   // $event['timestamp'] – Unix timestamp of the event
     *   // $event['raw']       – full raw payload array
     *
     * @param  string|null $mobileFromUrl  The {mobile_number} segment extracted
     *                                     from the URL by your router.
     * @return array Structured call event.
     */
    public function parseRequest(?string $mobileFromUrl = null): array
    {
        $payload = $this->readPayload();

        $mobile = $mobileFromUrl
            ?? $payload['mobile']
            ?? $payload['callerid']
            ?? $payload['CallerIDNum']
            ?? '';

        return [
            'mobile'    => $this->sanitizeMobile((string) $mobile),
            'status'    => strtoupper(trim($payload['status']    ?? $payload['dialstatus'] ?? '')),
            'caller_id' => trim($payload['callerid']             ?? $payload['CallerIDNum'] ?? $mobile),
            'channel'   => trim($payload['channel']              ?? $payload['Channel']     ?? ''),
            'duration'  => (int) ($payload['duration']           ?? $payload['Duration']    ?? 0),
            'timestamp' => (int) ($payload['timestamp']          ?? time()),
            'raw'       => $payload,
        ];
    }

    /**
     * Build the incoming-call callback URL for a given mobile number.
     *
     * Example:
     *   $url = $handler->buildCallbackUrl('0501234567');
     *   // https://172.16.0.200/jebaya/public/search_income_calls/0501234567
     *
     * @param  string $mobile Mobile number to embed in the URL.
     * @return string
     */
    public function buildCallbackUrl(string $mobile): string
    {
        $base = rtrim($this->callbackBaseUrl, '/');
        return $base . '/search_income_calls/' . rawurlencode($mobile);
    }

    /**
     * Build the hangup callback URL for a given mobile number.
     *
     * Example:
     *   $url = $handler->buildHangupUrl('0501234567');
     *   // https://172.16.0.200/jebaya/public/call_hangup/0501234567
     *
     * @param  string $mobile Mobile number to embed in the URL.
     * @return string
     */
    public function buildHangupUrl(string $mobile): string
    {
        $base = rtrim($this->callbackBaseUrl, '/');
        return $base . '/' . ltrim($this->hangupCallbackPath, '/') . '/' . rawurlencode($mobile);
    }

    /**
     * Parse a hangup callback from Asterisk.
     *
     * Returns the same structure as parseRequest() plus a 'hangup_cause' field.
     *
     * @param  string|null $mobileFromUrl The {mobile_number} URL segment.
     * @return array Structured hangup event.
     */
    public function parseHangup(?string $mobileFromUrl = null): array
    {
        $event                 = $this->parseRequest($mobileFromUrl);
        $payload               = $event['raw'];
        $event['hangup_cause'] = trim($payload['hangupcause'] ?? $payload['HangupCause'] ?? $payload['cause'] ?? '');
        $event['bill_seconds'] = (int) ($payload['billseconds'] ?? $payload['BillSeconds'] ?? $event['duration']);
        $event['type']         = 'hangup';
        return $event;
    }

    // -------------------------------------------------------------------------
    // Asterisk AGI / curl snippet generator
    // -------------------------------------------------------------------------

    /**
     * Generate the Asterisk dialplan snippet (extensions.conf) that fires
     * the incoming-call HTTP callback when a call arrives.
     *
     * Place this in the [incoming] context of your extensions.conf.
     *
     * @param  string $mobile Dialplan variable for the caller; default: ${CALLERID(num)}.
     * @return string Dialplan line.
     */
    public function buildDialplanCurlLine(string $mobile = '${CALLERID(num)}'): string
    {
        $base = rtrim($this->callbackBaseUrl, '/');
        $url  = $base . '/search_income_calls/' . $mobile;

        return 'same => n,System(curl -s -X POST "' . $url . '"'
             . ' -d "callerid=${CALLERID(num)}'
             . '&status=${DIALSTATUS}'
             . '&channel=${CHANNEL}'
             . '&duration=${CDR(duration)}'
             . '&timestamp=$(date +%s)"' . ')';
    }

    /**
     * Generate the Asterisk dialplan snippet that fires the hangup callback
     * when a call ends. Add this as the h-extension (hangup handler).
     *
     * Example extensions.conf entry:
     *   exten => h,1,NoOp(Call ended)
     *   same  => n,<result of this method>
     *
     * @param  string $mobile Dialplan variable for the caller; default: ${CALLERID(num)}.
     * @return string Dialplan line.
     */
    public function buildDialplanHangupCurlLine(string $mobile = '${CALLERID(num)}'): string
    {
        $base = rtrim($this->callbackBaseUrl, '/');
        $path = ltrim($this->hangupCallbackPath, '/');
        $url  = $base . '/' . $path . '/' . $mobile;

        return 'same => n,System(curl -s -X POST "' . $url . '"'
             . ' -d "callerid=${CALLERID(num)}'
             . '&status=HANGUP'
             . '&hangupcause=${HANGUPCAUSE}'
             . '&channel=${CHANNEL}'
             . '&duration=${CDR(duration)}'
             . '&billseconds=${CDR(billseconds)}'
             . '&timestamp=$(date +%s)"' . ')';
    }

    // -------------------------------------------------------------------------
    // Response helpers
    // -------------------------------------------------------------------------

    /**
     * Send a plain-text 200 OK back to Asterisk.
     * Asterisk ignores the body; it just needs a 2xx to know the request landed.
     *
     * @param  string $body Optional response body.
     */
    public function respond(string $body = 'OK'): void
    {
        http_response_code(200);
        header('Content-Type: text/plain');
        echo $body;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Read and merge all incoming data sources into a single flat array.
     * Asterisk can send data as query-string params, form fields, or JSON.
     */
    private function readPayload(): array
    {
        $payload = array_merge($_GET, $_POST);

        // Also try a JSON body (some Asterisk AGI scripts POST JSON).
        $body = file_get_contents('php://input');
        if ($body && strpos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $payload = array_merge($payload, $decoded);
            }
        }

        return $payload;
    }

    /**
     * Strip non-digit characters except a leading + from a mobile number.
     */
    private function sanitizeMobile(string $mobile): string
    {
        return preg_replace('/[^\d+]/', '', $mobile);
    }
}
