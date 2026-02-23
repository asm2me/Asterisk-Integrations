<?php

namespace Asterisk\Integration;

/**
 * HTTP client for making cURL requests to the ViciDial/Asterisk server.
 */
class ViciDialClient
{
    private int  $timeout;
    private int  $connectTimeout;
    private bool $sslVerify;

    public function __construct(?Config $config = null)
    {
        $config               = $config ?? new Config();
        $this->timeout        = (int)  $config->get('timeout', 30);
        $this->connectTimeout = (int)  $config->get('connect_timeout', 10);
        $this->sslVerify      = (bool) $config->get('ssl_verify', true);
    }

    /**
     * Send a POST request to the given URL with a JSON-encoded payload.
     * Data is wrapped in {"user": ...} as required by the ViciDial API.
     *
     * @param  string $url   The full ViciDial API endpoint URL (with query params).
     * @param  array  $data  Associative array of data to wrap in {"user": ...} and POST.
     * @return array{success: bool, status: int, response: string, data: array}
     * @throws \RuntimeException on cURL transport error.
     */
    public function post(string $url, array $data): array
    {
        $ch      = curl_init($url);
        $payload = json_encode(['user' => $data]);

        curl_setopt_array($ch, [
            CURLOPT_POST            => true,
            CURLOPT_POSTFIELDS      => $payload,
            CURLOPT_HTTPHEADER      => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_TIMEOUT         => $this->timeout,
            CURLOPT_CONNECTTIMEOUT  => $this->connectTimeout,
            CURLOPT_SSL_VERIFYPEER  => $this->sslVerify,
            CURLOPT_SSL_VERIFYHOST  => $this->sslVerify ? 2 : 0,
        ]);

        $result = curl_exec($ch);
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            throw new \RuntimeException("cURL error ({$errno}): {$error}");
        }

        return [
            'success'  => $status >= 200 && $status < 300,
            'status'   => $status,
            'response' => $result,
            'data'     => $data,
        ];
    }

    /**
     * POST $data directly as a JSON body to $url — no wrapping envelope.
     * Use this for CRM callback endpoints that expect a plain JSON object.
     *
     * @param  string $url  Target URL.
     * @param  array  $data Payload sent as-is: json_encode($data).
     * @return array{success: bool, status: int, response: string, data: array}
     * @throws \RuntimeException on cURL transport error.
     */
    public function postJson(string $url, array $data): array
    {
        $ch      = curl_init($url);
        $payload = json_encode($data);

        curl_setopt_array($ch, [
            CURLOPT_POST            => true,
            CURLOPT_POSTFIELDS      => $payload,
            CURLOPT_HTTPHEADER      => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_TIMEOUT         => $this->timeout,
            CURLOPT_CONNECTTIMEOUT  => $this->connectTimeout,
            CURLOPT_SSL_VERIFYPEER  => $this->sslVerify,
            CURLOPT_SSL_VERIFYHOST  => $this->sslVerify ? 2 : 0,
        ]);

        $result = curl_exec($ch);
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            throw new \RuntimeException("cURL error ({$errno}): {$error}");
        }

        return [
            'success'  => $status >= 200 && $status < 300,
            'status'   => $status,
            'response' => $result,
            'data'     => $data,
        ];
    }
}
