<?php

namespace SocialiteProviders\Ctrader;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\ProviderInterface;
use Laravel\Socialite\Two\User;
use SocialiteProviders\Manager\ConfigTrait;

/**
 * cTrader Open API – OAuth 2.0 Socialite Provider.
 *
 * Authentication flow (as per cTrader Open API documentation):
 *
 *  1.  Redirect the user to `https://id.ctrader.com/my/settings/openapi/grantingaccess/`
 *      with the required query parameters (client_id, redirect_uri, scope, product).
 *
 *  2.  cTrader redirects back to your redirect_uri with `?code=<authorisation_code>`.
 *      The authorisation code expires in ONE MINUTE and must be exchanged immediately.
 *
 *  3.  Exchange the authorisation code for an access token via a GET request to
 *      `https://openapi.ctrader.com/apps/token` with `grant_type=authorization_code`.
 *      The response contains:
 *          - accessToken  – valid for ~30 days (2,628,000 seconds)
 *          - refreshToken – no expiry; used to renew the access token
 *          - tokenType    – "bearer"
 *          - expiresIn    – seconds until expiry
 *
 *  4.  The socialite `User` is hydrated from the token response itself because cTrader
 *      does not expose a standard user-info REST endpoint.  The `id` is set to the
 *      raw `accessToken` value so downstream code can use `getid()` as a unique,
 *      stable identifier for the authenticated cTID session.
 *
 * Refresh token flow (called outside Socialite after initial login):
 *      GET https://openapi.ctrader.com/apps/token
 *          ?grant_type=refresh_token
 *          &refresh_token=<refresh_token>
 *          &client_id=<client_id>
 *          &client_secret=<client_secret>
 *
 * @see https://help.ctrader.com/open-api/account-authentication/
 */
class Provider extends AbstractProvider implements ProviderInterface
{
    use ConfigTrait;
    /**
     * The driver name used to register and resolve this provider.
     */
    public const IDENTIFIER = 'CTRADER';

    /**
     * cTrader authorisation endpoint.
     * The user is redirected here so they can grant your application access to
     * one or more of their trading accounts.
     */
    protected const AUTH_BASE_URL = 'https://id.ctrader.com/my/settings/openapi/grantingaccess/';

    /**
     * cTrader REST token endpoint.
     * Used to exchange authorisation codes for access tokens, and to refresh
     * access tokens using a refresh token.
     *
     * Note: cTrader uses a GET request (not POST) for this endpoint.
     */
    protected const TOKEN_BASE_URL = 'https://openapi.ctrader.com/apps/token';

    /**
     * Default OAuth scopes supported by cTrader Open API.
     *
     *  - "accounts" → read-only access (account info, statistics)
     *  - "trading"  → full access (account info + all permitted trading operations)
     */
    protected $scopes = ['accounts'];

    /**
     * The separator used between scopes (cTrader expects a space-delimited list,
     * but since we only use one scope at a time this is a safe default).
     */
    protected $scopeSeparator = ' ';

    // -------------------------------------------------------------------------
    // Core OAuth 2.0 Methods
    // -------------------------------------------------------------------------

    /**
     * Build the authorisation URL that the user is redirected to.
     *
     * Query parameters per the cTrader documentation:
     *  - client_id    (required) – unique identifier of your Open API application
     *  - redirect_uri (required) – registered redirect URI
     *  - scope        (required) – "accounts" or "trading"
     *  - product      (optional) – set to "web" to hide the header/footer (recommended for mobile)
     */
    protected function getAuthUrl($state): string
    {
        return $this->buildAuthUrlFromBase(static::AUTH_BASE_URL, $state);
    }

    /**
     * Returns the base URL for the token exchange endpoint.
     * AbstractProvider uses this when it calls `getAccessTokenResponse()`.
     */
    protected function getTokenUrl(): string
    {
        return static::TOKEN_BASE_URL;
    }

    /**
     * Override the token exchange to use a GET request.
     *
     * The cTrader Open API specification explicitly requires a GET request to
     * the token endpoint – not the typical POST used by most OAuth 2.0 providers.
     *
     * cTrader token endpoint query parameters:
     *  - grant_type    (required) – "authorization_code"
     *  - code          (required) – the authorisation code received in the callback
     *  - redirect_uri  (required) – must match the URI registered in the application
     *  - client_id     (required)
     *  - client_secret (required)
     *
     * @param string $code The authorisation code from the callback query string.
     * @return array<string, mixed> Decoded token response body.
     * @throws GuzzleException
     */
    public function getAccessTokenResponse($code): array
    {
        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->get($this->getTokenUrl(), $this->getTokenFields($code));

        return (array) json_decode((string) $response->getBody(), true);
    }

    /**
     * Build the query parameters sent to the token endpoint.
     *
     * @param  string  $code  The authorisation code.
     * @return array<string, string>
     */
    protected function getTokenFields($code): array
    {
        return [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUrl,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ];
    }

    /**
     * Build the additional query string parameters for the authorisation URL.
     *
     * The `product=web` parameter is recommended by cTrader to strip the header
     * and footer from the authorisation screen, improving the mobile experience.
     *
     * @param  string  $state  CSRF state token generated by Socialite.
     * @return array<string, string>
     */
    protected function getCodeFields($state = null): array
    {
        $fields = parent::getCodeFields($state);

        // Recommended by cTrader to improve the mobile/WebView experience.
        $fields['product'] = 'web';

        return $fields;
    }

    // -------------------------------------------------------------------------
    // User Hydration
    // -------------------------------------------------------------------------

    /**
     * cTrader does not expose a standard user-info REST endpoint.
     * All available identity information is contained within the token response.
     *
     * The access token itself acts as the unique identifier for the authenticated
     * cTID session. Downstream code (e.g. SocialiteController) stores the token
     * so it can be used to call ProtoOAGetAccountListByAccessTokenReq etc.
     *
     * @param  string  $token  The access token.
     * @return array<string, mixed> An array containing the token payload.
     */
    protected function getUserByToken($token): array
    {
        $payload = [
            'payloadType' => 2151,
            'payload' => [
                'access' . 'Token' => $token,
            ],
        ];

        return array_merge($this->sendApiRequest($payload), ['access_token' => $token]);
    }

    /**
     * Map the raw token payload into a Socialite User object.
     *
     * Fields populated:
     *  - id             → the access token (unique per cTID session)
     *  - token          → access token
     *  - refreshToken   → refresh token
     *  - expiresIn      → token TTL in seconds (~2,628,000 ≈ 30 days)
     *
     * Email and name are not available from cTrader without additional ProtoBuf
     * message round-trips; they are left null for the controller to handle.
     *
     * @param  array<string, mixed>  $user  Raw user data (token payload).
     */
    protected function mapUserToObject(array $user): User
    {
        $id = $user['payload']['profile']['userId'] ?? $user['access_token'];

        return (new User)->setRaw($user)->map([
            'id' => $id,
            'nickname' => null,
            'name' => null,
            'email' => $id . '@ctrader.com',
            'avatar' => null,
        ]);
    }

    /**
     * Send a message to the cTrader Open API.
     *
     * @param  array  $payload
     * @return array
     */
    protected function sendApiRequest(array $payload): array
    {
        $host = 'live.ctraderapi.com';
        $port = 5036;

        $stream = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 5);

        if (! $stream) {
            return [];
        }

        $json = json_encode($payload);
        $length = strlen($json);

        // Prepend 4-byte length prefix.
        fwrite($stream, pack('N', $length));
        fwrite($stream, $json);

        // Read 4-byte length prefix.
        $header = fread($stream, 4);
        if (! $header) {
            fclose($stream);
            return [];
        }

        $resLength = unpack('N', $header)[1];
        $resJson = '';
        while (strlen($resJson) < $resLength && ! feof($stream)) {
            $resJson .= fread($stream, $resLength - strlen($resJson));
        }
        fclose($stream);

        return (array) json_decode($resJson, true);
    }

    // -------------------------------------------------------------------------
    // Token Refresh Helper
    // -------------------------------------------------------------------------

    /**
     * Refresh an expired access token using the stored refresh token.
     *
     * This method is a convenience helper that can be called outside of the
     * normal Socialite OAuth flow (e.g. from a scheduled command or middleware)
     * to renew a user's access token before it expires.
     *
     * Per the cTrader documentation, once an access token expires the new access
     * token AND refresh token must replace the old ones; the old values are
     * automatically invalidated upon a successful refresh.
     *
     * @param  string  $refreshToken  The refresh token stored from the initial login.
     * @return array<string, mixed> The full token response (accessToken, refreshToken, expiresIn, …)
     *
     * @throws GuzzleException
     */
    public function refreshToken($refreshToken): array
    {
        $response = $this->getHttpClient()->get($this->getTokenUrl(), [
            RequestOptions::HEADERS => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            RequestOptions::QUERY => [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ],
        ]);

        return (array) json_decode((string) $response->getBody(), true);
    }

    // -------------------------------------------------------------------------
    // Internal Overrides
    // -------------------------------------------------------------------------

    /**
     * The cTrader token response uses camelCase keys rather than snake_case.
     * Override the parent token-parsing helpers to map the correct keys.
     *
     * Standard Socialite expects: access_token, refresh_token, expires_in
     * cTrader returns:            accessToken,  refreshToken,  expiresIn
     *
     * @param  array<string, mixed>  $body  Decoded token response.
     * @return string The access token.
     */
    protected function parseAccessToken($body): string
    {
        return (string) Arr::get($body, 'accessToken', '');
    }

    /**
     * @param  array<string, mixed>  $body  Decoded token response.
     * @return string The refresh token.
     */
    protected function parseRefreshToken($body): string
    {
        return (string) Arr::get($body, 'refreshToken', '');
    }

    /**
     * @param  array<string, mixed>  $body  Decoded token response.
     * @return int Token lifetime in seconds.
     */
    protected function parseExpiresIn($body): int
    {
        return (int) Arr::get($body, 'expiresIn', 0);
    }
}
