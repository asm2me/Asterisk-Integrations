<?php

namespace Asterisk\Integration;

/**
 * File-based logger with daily rotation and configurable log levels.
 *
 * Log files are written to:   <log_dir>/service-YYYY-MM-DD.log
 * Each line format:           [2026-02-24 14:05:30] [INFO ] message
 *
 * Config keys used:
 *   log_dir       – directory for log files (default: <project>/logs)
 *   log_level     – minimum level to record: DEBUG | INFO | WARNING | ERROR  (default: INFO)
 *   log_to_stdout – also echo every entry to stdout (default: true)
 */
class Logger
{
    const DEBUG   = 'DEBUG';
    const INFO    = 'INFO';
    const WARNING = 'WARNING';
    const ERROR   = 'ERROR';

    /** Numeric weight for each level (higher = more severe). */
    private static array $weights = [
        self::DEBUG   => 0,
        self::INFO    => 1,
        self::WARNING => 2,
        self::ERROR   => 3,
    ];

    private string $logDir;
    private int    $minWeight;
    private bool   $toStdout;

    public function __construct(?Config $config = null)
    {
        $config           = $config ?? new Config();
        $this->logDir     = rtrim((string) $config->get('log_dir', __DIR__ . '/../logs'), '/\\');
        $minLevel         = strtoupper((string) $config->get('log_level', self::INFO));
        $this->minWeight  = self::$weights[$minLevel] ?? self::$weights[self::INFO];
        $this->toStdout   = (bool) $config->get('log_to_stdout', true);

        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }

    // ── Convenience methods ───────────────────────────────────────────────────

    public function debug(string $message): void
    {
        $this->write(self::DEBUG, $message);
    }

    public function info(string $message): void
    {
        $this->write(self::INFO, $message);
    }

    public function warning(string $message): void
    {
        $this->write(self::WARNING, $message);
    }

    public function error(string $message): void
    {
        $this->write(self::ERROR, $message);
    }

    /**
     * Write a log entry at an arbitrary level.
     *
     * @param string $level   One of the Logger::* constants.
     * @param string $message Log message.
     */
    public function log(string $level, string $message): void
    {
        $this->write(strtoupper($level), $message);
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    private function write(string $level, string $message): void
    {
        $weight = self::$weights[$level] ?? 0;
        if ($weight < $this->minWeight) {
            return;
        }

        // Pad level to 7 chars so columns align nicely.
        $line = sprintf(
            '[%s] [%-7s] %s' . PHP_EOL,
            date('Y-m-d H:i:s'),
            $level,
            $message
        );

        // Write to daily log file.
        $file = $this->logDir . '/service-' . date('Y-m-d') . '.log';
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);

        // Mirror to stdout (captured by systemd/journald).
        if ($this->toStdout) {
            $out = ($level === self::ERROR || $level === self::WARNING) ? STDERR : STDOUT;
            fwrite($out, $line);
        }
    }
}
