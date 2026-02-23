<?php

namespace Asterisk\Integration;

/**
 * Holds resolved configuration values for the integration module.
 *
 * Usage – load defaults from config.php and override selectively:
 *
 *   $config = new Config(['server' => '10.0.0.5']);
 *
 * Or supply the full array yourself (no file loaded):
 *
 *   $config = new Config($myArray, false);
 */
class Config
{
    private array $data;

    /**
     * @param array $overrides    Key/value pairs that take precedence over defaults.
     * @param bool  $loadDefaults When true, merges overrides on top of config.php.
     */
    public function __construct(array $overrides = [], bool $loadDefaults = true)
    {
        $defaults   = $loadDefaults ? (require __DIR__ . '/../config.php') : [];
        $this->data = array_merge($defaults, $overrides);
    }

    /**
     * Retrieve a configuration value.
     *
     * @param  string $key
     * @param  mixed  $default Returned when the key is not set.
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * Return every key/value pair (useful for debugging).
     */
    public function all(): array
    {
        return $this->data;
    }
}
