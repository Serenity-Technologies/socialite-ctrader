<?php

namespace SocialiteProviders\Ctrader;

use Exception;
use Google\Protobuf\Internal\Message;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\ProviderInterface;
use Laravel\Socialite\Two\User;
use SocialiteProviders\Ctrader\Protobuf\ProtoErrorRes;
use SocialiteProviders\Ctrader\Protobuf\ProtoMessage;
use SocialiteProviders\Ctrader\Protobuf\ProtoOAApplicationAuthReq;
use SocialiteProviders\Ctrader\Protobuf\ProtoOAApplicationAuthRes;
use SocialiteProviders\Ctrader\Protobuf\ProtoOAErrorRes;
use SocialiteProviders\Ctrader\Protobuf\ProtoOAGetCtidProfileByTokenReq;
use SocialiteProviders\Ctrader\Protobuf\ProtoOAGetCtidProfileByTokenRes;
use SocialiteProviders\Ctrader\Protobuf\ProtoOAPayloadType;
use SocialiteProviders\Ctrader\Protobuf\ProtoPayloadType;
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
 *  4.  The socialite `User` is hydrated from the cTID profile retrieval using Protobuf
 *      messages over a raw SSL socket (port 5035).
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
     * @throws ConnectionException
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
     * We use the Open API Protobuf protocol to retrieve the cTID profile.
     *
     * @param  string  $token  The access token.
     * @return array<string, mixed> An array containing the profile data.
     */
    protected function getUserByToken($token): array
    {
        $req = new ProtoOAGetCtidProfileByTokenReq();
        $req->setAccessToken($token);

        $response = $this->sendApiRequest(ProtoOAPayloadType::PROTO_OA_GET_CTID_PROFILE_BY_TOKEN_REQ, $req);

        if ($response instanceof ProtoOAGetCtidProfileByTokenRes) {
            return [
                'userId' => $response->getProfile()->getUserId(),
                'access_token' => $token,
            ];
        }

        return ['access_token' => $token];
    }

    /**
     * Map the raw token payload into a Socialite User object.
     *
     * Fields populated:
     *  - id             → the cTID user ID
     *  - token          → access token
     *  - email          → generated from user ID
     *
     * @param  array<string, mixed>  $user  Raw user data.
     */
    protected function mapUserToObject(array $user): User
    {
        $id = $user['userId'] ?? $user['access_token'];

        return (new User)->setRaw($user)->map([
            'id' => $id,
            'nickname' => null,
            'name' => null,
            'email' => $id . '@ctrader.com',
            'avatar' => null,
        ]);
    }

    /**
     * Send a Protobuf message to the cTrader Open API with application authentication.
     *
     * @param  int  $payloadType
     * @param  Message  $message
     * @return Message|null
     */
    protected function sendApiRequest(int $payloadType, Message $message): ?Message
    {
        $host = 'live.ctraderapi.com';
        $port = 5035;

        $stream = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 5);

        if (! $stream) {
            return null;
        }

        // 1. Authenticate Application
        $authReq = new ProtoOAApplicationAuthReq();
        $authReq->setClientId($this->clientId);
        $authReq->setClientSecret($this->clientSecret);

        $this->writeMessage($stream, ProtoOAPayloadType::PROTO_OA_APPLICATION_AUTH_REQ, $authReq);
        $this->readMessage($stream); // Consume auth response

        // 2. Send actual payload
        $this->writeMessage($stream, $payloadType, $message);
        $response = $this->readMessage($stream);

        fclose($stream);

        return $response;
    }

    protected function writeMessage($stream, int $payloadType, Message $message)
    {
        $protoMessage = new ProtoMessage();
        $protoMessage->setPayloadType($payloadType);
        $protoMessage->setPayload($message->serializeToString());

        $data = $protoMessage->serializeToString();
        $length = strlen($data);
        fwrite($stream, pack('N', $length));
        fwrite($stream, $data);
    }

    /**
     * @throws Exception
     */
    protected function readMessage($stream): ?Message
    {
        $header = fread($stream, 4);
        if (! $header || strlen($header) < 4) {
            return null;
        }

        $resLength = unpack('N', $header)[1];

        // Sanity check
        if ($resLength > 1024 * 512) {
            return null;
        }

        $data = '';
        while (strlen($data) < $resLength && ! feof($stream)) {
            $chunk = fread($stream, min($resLength - strlen($data), 8192));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
        }

        $protoMessage = new ProtoMessage();
        $protoMessage->mergeFromString($data);

        $payloadType = $protoMessage->getPayloadType();
        $payload = $protoMessage->getPayload();

        $class = $this->getMessageClass($payloadType);
        if (! $class) {
            return null;
        }

        $message = new $class();
        $message->mergeFromString($payload);

        return $message;
    }

    protected function getMessageClass(int $payloadType): ?string
    {
        $map = [
            ProtoPayloadType::ERROR_RES => ProtoErrorRes::class,
            ProtoOAPayloadType::PROTO_OA_APPLICATION_AUTH_RES => ProtoOAApplicationAuthRes::class,
            ProtoOAPayloadType::PROTO_OA_ERROR_RES => ProtoOAErrorRes::class,
            ProtoOAPayloadType::PROTO_OA_GET_CTID_PROFILE_BY_TOKEN_RES => ProtoOAGetCtidProfileByTokenRes::class,
        ];

        return $map[$payloadType] ?? null;
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
