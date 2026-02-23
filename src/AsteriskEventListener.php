<?php

namespace Asterisk\Integration;

/**
 * Persistent Asterisk Manager Interface (AMI) event listener.
 *
 * Keeps a long-running TCP connection open to Asterisk and dispatches every
 * inbound event to registered PHP callbacks. Designed to run as a daemon.
 *
 * Usage:
 *   $listener = new AsteriskEventListener($config);
 *   $listener->on('Newchannel', fn($e) => ...);
 *   $listener->on('Hangup',     fn($e) => ...);
 *   $listener->start();   // blocks forever, auto-reconnects on drop
 */
class AsteriskEventListener
{
    /** @var resource|null */
    private $socket = null;

    /** @var array<string, callable[]> event-type => handlers */
    private array $handlers = [];

    /** @var callable[] called on every event regardless of type */
    private array $catchAllHandlers = [];

    private bool   $running        = false;
    private string $host;
    private int    $port;
    private int    $timeout;
    private int    $reconnectDelay;
    private string $username;
    private string $secret;
    private Logger $logger;

    /** Stream read timeout in seconds (allows checking $running periodically). */
    private const READ_TIMEOUT = 5;

    public function __construct(?Config $config = null, ?Logger $logger = null)
    {
        $config               = $config ?? new Config();
        $this->host           = (string) $config->get('ami_host', $config->get('server'));
        $this->port           = (int)    $config->get('ami_port', 5038);
        $this->timeout        = (int)    $config->get('timeout', 30);
        $this->reconnectDelay = (int)    $config->get('ami_reconnect_delay', 5);
        $this->username       = (string) $config->get('ami_username', '');
        $this->secret         = (string) $config->get('ami_secret', '');
        $this->logger         = $logger ?? new Logger($config);
    }

    // -------------------------------------------------------------------------
    // Event registration
    // -------------------------------------------------------------------------

    /**
     * Register a handler for one or more AMI event types.
     *
     * @param  string|string[] $events  Event name(s), e.g. 'Newchannel' or ['Newchannel','Hangup'].
     *                                  Pass '*' to catch every event.
     * @param  callable        $handler fn(array $event): void
     * @return $this
     */
    public function on($events, callable $handler): self
    {
        foreach ((array) $events as $event) {
            if ($event === '*') {
                $this->catchAllHandlers[] = $handler;
            } else {
                $this->handlers[$event][] = $handler;
            }
        }
        return $this;
    }

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    /**
     * Start the listener. Blocks until stop() is called or the process exits.
     * Automatically reconnects if the connection is lost.
     */
    public function start(): void
    {
        $this->running = true;
        $this->logger->info('Service starting.');

        while ($this->running) {
            try {
                $this->connect();

                if (!$this->login()) {
                    throw new \RuntimeException('AMI login failed.');
                }

                $this->logger->info("Connected and authenticated to {$this->host}:{$this->port}");
                $this->loop();

            } catch (\Throwable $e) {
                $this->logger->error($e->getMessage());
                $this->closeSocket();

                if ($this->running) {
                    $this->logger->warning("Reconnecting in {$this->reconnectDelay}s...");
                    sleep($this->reconnectDelay);
                }
            }
        }

        $this->logger->info('Service stopped.');
    }

    /**
     * Signal the listener to stop after the current event (or read timeout).
     */
    public function stop(): void
    {
        $this->running = false;
        $this->logger->info('Stop requested.');
    }

    // -------------------------------------------------------------------------
    // Connection helpers (public so service.php can pre-check connectivity)
    // -------------------------------------------------------------------------

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
                "Cannot connect to AMI at {$this->host}:{$this->port} — ({$errno}) {$errstr}"
            );
        }

        // Short read timeout so the loop can check $running periodically.
        stream_set_timeout($this->socket, self::READ_TIMEOUT);

        // Consume the greeting: "Asterisk Call Manager/x.x"
        fgets($this->socket);
    }

    public function login(): bool
    {
        if (!$this->socket) {
            return false;
        }

        $this->write([
            'Action'   => 'Login',
            'Username' => $this->username,
            'Secret'   => $this->secret,
        ]);

        $response = $this->readPacket();
        return isset($response['Response'])
            && strtolower($response['Response']) === 'success';
    }

    public function isConnected(): bool
    {
        return $this->socket !== null;
    }

    // -------------------------------------------------------------------------
    // Internal event loop
    // -------------------------------------------------------------------------

    /**
     * Blocking read loop — runs until the socket is closed or an error occurs.
     */
    private function loop(): void
    {
        while ($this->running && $this->socket) {
            $packet = $this->readPacket();

            if ($packet === null) {
                // Stream timeout or EOF — EOF means Asterisk closed the connection.
                if (feof($this->socket)) {
                    $this->logger->warning('AMI connection closed by server.');
                    throw new \RuntimeException('AMI connection closed by server.');
                }
                // Timeout: no data — just loop again to check $running.
                continue;
            }

            if (isset($packet['Event'])) {
                $this->dispatch($packet);
            }
        }
    }

    /**
     * Read one AMI packet (key:value lines terminated by a blank line).
     * Returns null on stream timeout (no data) or empty packet.
     *
     * @return array<string, string>|null
     */
    private function readPacket(): ?array
    {
        $packet = [];

        while (true) {
            $line = fgets($this->socket, 4096);

            if ($line === false) {
                // Timeout or EOF
                return empty($packet) ? null : $packet;
            }

            $line = rtrim($line, "\r\n");

            if ($line === '') {
                // Blank line = end of packet
                return empty($packet) ? null : $packet;
            }

            if (strpos($line, ':') !== false) {
                [$key, $value]     = explode(':', $line, 2);
                $packet[trim($key)] = trim($value);
            }
        }
    }

    /**
     * Dispatch a parsed event to all registered handlers.
     */
    private function dispatch(array $event): void
    {
        $type = $event['Event'] ?? '';

        foreach ($this->catchAllHandlers as $handler) {
            ($handler)($event);
        }

        if (isset($this->handlers[$type])) {
            foreach ($this->handlers[$type] as $handler) {
                ($handler)($event);
            }
        }
    }

    /**
     * Write an AMI action packet to the socket.
     */
    private function write(array $action): void
    {
        if (!$this->socket) {
            return;
        }

        $packet = '';
        foreach ($action as $key => $value) {
            $packet .= "{$key}: {$value}\r\n";
        }
        $packet .= "\r\n";

        fwrite($this->socket, $packet);
    }

    private function closeSocket(): void
    {
        if ($this->socket) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    // -------------------------------------------------------------------------
    // Logger access
    // -------------------------------------------------------------------------

    /**
     * Return the Logger instance (so service.php can share it for event handlers).
     */
    public function getLogger(): Logger
    {
        return $this->logger;
    }
}
