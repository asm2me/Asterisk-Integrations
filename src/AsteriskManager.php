<?php

namespace Asterisk\Integration;

/**
 * Asterisk Manager Interface (AMI) client.
 *
 * Communicates with Asterisk directly over a TCP socket on port 5038
 * using the AMI text protocol.
 *
 * Typical flow:
 *   $ami = new AsteriskManager($config);
 *   $ami->connect();
 *   $ami->login('manager_user', 'manager_secret');
 *
 *   $ami->originate('SIP/8001', '0501234567');
 *   $ami->hangup('SIP/8001-00000001');
 *
 *   $ami->disconnect();
 */
class AsteriskManager
{
    /** @var resource|null */
    private $socket = null;

    private string $host;
    private int    $port;
    private int    $timeout;
    private int    $actionIdSeq = 0;

    public function __construct(?Config $config = null)
    {
        $config        = $config ?? new Config();
        $this->host    = (string) $config->get('ami_host', $config->get('server'));
        $this->port    = (int)    $config->get('ami_port', 5038);
        $this->timeout = (int)    $config->get('timeout', 30);
    }

    // -------------------------------------------------------------------------
    // Connection
    // -------------------------------------------------------------------------

    /**
     * Open a TCP connection to the AMI and consume the greeting banner.
     *
     * @throws \RuntimeException if the socket cannot be opened.
     */
    public function connect(): void
    {
        $this->socket = fsockopen(
            $this->host,
            $this->port,
            $errno,
            $errstr,
            $this->timeout
        );

        if (!$this->socket) {
            throw new \RuntimeException(
                "AMI connection to {$this->host}:{$this->port} failed ({$errno}): {$errstr}"
            );
        }

        stream_set_timeout($this->socket, $this->timeout);

        // Consume the greeting line: "Asterisk Call Manager/x.x.x"
        fgets($this->socket);
    }

    /**
     * Authenticate with the AMI.
     *
     * @param  string $username AMI manager username (manager.conf).
     * @param  string $secret   AMI manager secret.
     * @return bool   true on success.
     * @throws \RuntimeException if not connected.
     */
    public function login(string $username, string $secret): bool
    {
        $response = $this->sendAction([
            'Action'   => 'Login',
            'Username' => $username,
            'Secret'   => $secret,
        ]);

        return isset($response['Response'])
            && strtolower($response['Response']) === 'success';
    }

    /**
     * Send a Logoff action and close the socket.
     */
    public function disconnect(): void
    {
        if ($this->socket) {
            try {
                $this->sendAction(['Action' => 'Logoff']);
            } catch (\Throwable $e) {
                // best-effort
            }
            fclose($this->socket);
            $this->socket = null;
        }
    }

    // -------------------------------------------------------------------------
    // Call actions
    // -------------------------------------------------------------------------

    /**
     * Originate (place) an outbound call.
     *
     * @param  string $channel   Originating channel, e.g. "SIP/8001" or "Local/8001@agents".
     * @param  string $extension Destination extension to dial.
     * @param  string $context   Dialplan context (default: 'default').
     * @param  string $priority  Dialplan priority (default: '1').
     * @param  array  $variables Key/value channel variables to set, e.g. ['CALLERID' => '12345'].
     * @return array  AMI response.
     */
    public function originate(
        string $channel,
        string $extension,
        string $context   = 'default',
        string $priority  = '1',
        array  $variables = []
    ): array {
        $action = [
            'Action'   => 'Originate',
            'Channel'  => $channel,
            'Exten'    => $extension,
            'Context'  => $context,
            'Priority' => $priority,
            'Timeout'  => $this->timeout * 1000,
            'Async'    => 'true',
        ];

        if ($variables) {
            $action['Variable'] = implode(',', array_map(
                fn($k, $v) => "{$k}={$v}",
                array_keys($variables),
                array_values($variables)
            ));
        }

        return $this->sendAction($action);
    }

    /**
     * Hang up a channel.
     *
     * @param  string $channel Full channel name, e.g. "SIP/8001-00000001".
     * @param  int    $cause   Hangup cause code (16 = Normal call clearing).
     * @return array  AMI response.
     */
    public function hangup(string $channel, int $cause = 16): array
    {
        return $this->sendAction([
            'Action'  => 'Hangup',
            'Channel' => $channel,
            'Cause'   => (string) $cause,
        ]);
    }

    /**
     * Redirect (blind-transfer) a channel to a new extension.
     *
     * @param  string $channel  Channel to redirect.
     * @param  string $extension Destination extension.
     * @param  string $context  Dialplan context.
     * @param  string $priority Dialplan priority.
     * @return array  AMI response.
     */
    public function redirect(
        string $channel,
        string $extension,
        string $context  = 'default',
        string $priority = '1'
    ): array {
        return $this->sendAction([
            'Action'   => 'Redirect',
            'Channel'  => $channel,
            'Exten'    => $extension,
            'Context'  => $context,
            'Priority' => $priority,
        ]);
    }

    /**
     * Park a call (hold).
     *
     * @param  string $channel      Channel to park (the customer leg).
     * @param  string $parkChannel  Channel that requested the park (the agent leg).
     * @param  string $parkingLot   Parking lot name defined in res_parking.conf.
     * @return array  AMI response.
     */
    public function park(
        string $channel,
        string $parkChannel,
        string $parkingLot = 'default'
    ): array {
        return $this->sendAction([
            'Action'     => 'Park',
            'Channel'    => $channel,
            'Channel2'   => $parkChannel,
            'ParkingLot' => $parkingLot,
        ]);
    }

    /**
     * Get the status of a channel (or all channels if empty).
     *
     * @param  string $channel Specific channel name, or '' for all channels.
     * @return array  AMI response.
     */
    public function channelStatus(string $channel = ''): array
    {
        $action = ['Action' => 'Status'];
        if ($channel !== '') {
            $action['Channel'] = $channel;
        }
        return $this->sendAction($action);
    }

    /**
     * List all active channels.
     *
     * @return array  AMI response.
     */
    public function listChannels(): array
    {
        return $this->sendAction(['Action' => 'CoreShowChannels']);
    }

    // -------------------------------------------------------------------------
    // Low-level send / receive
    // -------------------------------------------------------------------------

    /**
     * Send an arbitrary AMI action and return the first response packet.
     *
     * An 'ActionID' is automatically appended to every action so responses
     * can be correlated when needed.
     *
     * @param  array $action Associative array of AMI key/value pairs.
     * @return array Parsed AMI response (key => value).
     * @throws \RuntimeException if not connected.
     */
    public function sendAction(array $action): array
    {
        if (!$this->socket) {
            throw new \RuntimeException('Not connected. Call connect() first.');
        }

        $action['ActionID'] = $this->nextActionId();

        $packet = '';
        foreach ($action as $key => $value) {
            $packet .= "{$key}: {$value}\r\n";
        }
        $packet .= "\r\n";

        fwrite($this->socket, $packet);

        return $this->readResponse();
    }

    /**
     * Read AMI key/value lines until a blank line terminates the packet.
     *
     * @return array Parsed response lines as key => value.
     */
    private function readResponse(): array
    {
        $response = [];

        while (true) {
            $line = fgets($this->socket, 4096);

            if ($line === false || rtrim($line) === '') {
                break;
            }

            if (strpos($line, ':') !== false) {
                [$key, $value]     = explode(':', $line, 2);
                $response[trim($key)] = trim($value);
            }
        }

        return $response;
    }

    /**
     * Generate a unique ActionID for each request.
     */
    private function nextActionId(): string
    {
        return 'ami-' . (++$this->actionIdSeq) . '-' . time();
    }

    /**
     * Whether the socket is currently open.
     */
    public function isConnected(): bool
    {
        return $this->socket !== null;
    }
}
