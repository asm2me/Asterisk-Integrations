<?php

namespace Asterisk\Integration;

/**
 * Asterisk / ViciDial integration module.
 *
 * Wraps every ViciDial API action used by the CRM:
 *   - getAuthUrl   : build the agent login URL (for window.open)
 *   - callClient   : external_dial – place an outbound call
 *   - holdClient   : park_call PARK_CUSTOMER – hold the active call
 *   - unHoldClient : park_call GRAB_CUSTOMER – resume a held call
 *   - hangUpClient : external_hangup – hang up the active call
 */
class AsteriskIntegration
{
    private string $server;
    private ViciDialClient $client;

    /**
     * @param string           $server  IP or hostname of the Asterisk/ViciDial server.
     * @param ViciDialClient|null $client Inject a custom client (useful for testing).
     */
    public function __construct(string $server, ?ViciDialClient $client = null)
    {
        $this->server = $server;
        $this->client = $client ?? new ViciDialClient();
    }

    // -------------------------------------------------------------------------
    // Auth
    // -------------------------------------------------------------------------

    /**
     * Build the ViciDial agent-login URL.
     *
     * Equivalent to the original `data` branch that echoes:
     *   onload="window.open('$url', '_blank');"
     *
     * @param array{
     *   ext: string,
     *   ext_password: string,
     *   voip_login: string,
     *   voip_password: string,
     *   campaign: string
     * } $user  User credential fields (mirrors Auth::user() properties).
     * @return string The full URL ready to be opened in a browser tab.
     */
    public function getAuthUrl(array $user): string
    {
        $params = http_build_query([
            'phone_login' => $user['ext'],
            'phone_pass'  => $user['ext_password'],
            'VD_login'    => $user['voip_login'],
            'VD_pass'     => $user['voip_password'],
            'VD_campaign' => $user['campaign'],
        ]);

        return "http://{$this->server}/agc/vicidial.php?{$params}";
    }

    /**
     * Return the JS onload attribute string that opens the agent login page.
     *
     * @param array $user See getAuthUrl() for the expected keys.
     * @return string  e.g.  onload="window.open('http://...', '_blank');"
     */
    public function getAuthOnload(array $user): string
    {
        $url = $this->getAuthUrl($user);
        return "onload=\"window.open('{$url}', '_blank');\"";
    }

    // -------------------------------------------------------------------------
    // Call actions
    // -------------------------------------------------------------------------

    /**
     * Place an outbound call to $phone as the given agent.
     *
     * Maps to:  /connect/functions.php?function=external_dial&value=<phone>
     *
     * @param  string $phone     Destination phone number.
     * @param  string $agentUser ViciDial agent username.
     * @return array{success: bool, status: int, response: string, data: array}
     */
    public function callClient(string $phone, string $agentUser): array
    {
        $url = $this->buildFunctionUrl([
            'agent_user' => $agentUser,
            'function'   => 'external_dial',
            'value'      => $phone,
        ]);

        return $this->client->post($url, [
            'server' => $this->server,
            'phone'  => $phone,
            'user'   => $agentUser,
        ]);
    }

    /**
     * Place the active call on hold (park it).
     *
     * Maps to:  /connect/functions.php?function=park_call&value=PARK_CUSTOMER
     *
     * @param  string $agentUser ViciDial agent username.
     * @param  string $phonePass Agent phone/extension password.
     * @return array{success: bool, status: int, response: string, data: array}
     */
    public function holdClient(string $agentUser, string $phonePass): array
    {
        $url = $this->buildFunctionUrl([
            'user'       => $agentUser,
            'pass'       => $phonePass,
            'agent_user' => $agentUser,
            'function'   => 'park_call',
            'value'      => 'PARK_CUSTOMER',
        ]);

        return $this->client->post($url, [
            'server' => $this->server,
            'user'   => $agentUser,
        ]);
    }

    /**
     * Resume a held (parked) call.
     *
     * Maps to:  /connect/functions.php?function=park_call&value=GRAB_CUSTOMER
     *
     * @param  string $agentUser ViciDial agent username.
     * @param  string $phonePass Agent phone/extension password.
     * @return array{success: bool, status: int, response: string, data: array}
     */
    public function unHoldClient(string $agentUser, string $phonePass): array
    {
        $url = $this->buildFunctionUrl([
            'user'       => $agentUser,
            'pass'       => $phonePass,
            'agent_user' => $agentUser,
            'function'   => 'park_call',
            'value'      => 'GRAB_CUSTOMER',
        ]);

        return $this->client->post($url, [
            'server' => $this->server,
            'user'   => $agentUser,
        ]);
    }

    /**
     * Hang up the active call.
     *
     * Maps to:  /connect/functions.php?function=external_hangup&value=1
     *
     * @param  string $agentUser ViciDial agent username.
     * @param  string $phonePass Agent phone/extension password.
     * @return array{success: bool, status: int, response: string, data: array}
     */
    public function hangUpClient(string $agentUser, string $phonePass): array
    {
        $url = $this->buildFunctionUrl([
            'user'       => $agentUser,
            'pass'       => $phonePass,
            'agent_user' => $agentUser,
            'function'   => 'external_hangup',
            'value'      => '1',
        ]);

        return $this->client->post($url, [
            'server' => $this->server,
            'user'   => $agentUser,
        ]);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Build a /connect/functions.php URL for this server with a fixed
     * source=Mani prefix plus the provided query parameters.
     *
     * @param  array $params Additional query string parameters.
     * @return string
     */
    private function buildFunctionUrl(array $params): string
    {
        $query = http_build_query(array_merge(['source' => 'Mani'], $params));
        return "http://{$this->server}/connect/functions.php?{$query}";
    }
}
