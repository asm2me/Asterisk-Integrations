<?php

namespace Asterisk\Integration;

/**
 * HTTP client for making cURL requests to the ViciDial/Asterisk server.
 */
class ViciDialClient
{
    private int $timeout;
    private int $connectTimeout;

    public function __construct(?Config $config = null)
    {
        $config               = $config ?? new Config();
        $this->timeout        = (int) $config->get('timeout', 30);
        $this->connectTimeout = (int) $config->get('connect_timeout', 10);
    }

    /**
     * Send a POST request to the given URL with a JSON-encoded payload.
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
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
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
