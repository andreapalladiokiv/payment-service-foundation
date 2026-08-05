<?php

declare(strict_types=1);

namespace Techork\PaymentService\Revolut;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use RuntimeException;

/**
 * Performs the Revolut Business API OAuth 2.0 token exchanges
 * (https://developer.revolut.com/docs/guides/manage-accounts/get-started/make-your-first-api-request).
 *
 * Both grant types Revolut supports authenticate the caller with the same
 * signed RS256 JWT *client assertion*, so they live together here:
 *
 *  - {@see exchangeAuthorizationCode()} — the one-time bootstrap. After an
 *    admin authorises the API app in the Revolut Business portal, the browser
 *    is redirected to the registered OAuth redirect URI with a short-lived
 *    `code`. Exchanging it yields the long-lived `refresh_token` that is then
 *    stored with the gateway credentials. This is run out-of-band, not during
 *    normal traffic.
 *
 *  - {@see refreshAccessToken()} — the runtime path. {@see RevolutClient}
 *    swaps the stored `refresh_token` for a fresh 40-minute `access_token`
 *    before making API calls.
 *
 * The authenticator is transport-only: it neither stores tokens nor decides
 * when to refresh them — callers own that.
 */
final class RevolutAuthenticator
{
    public const string TOKEN_PATH = 'api/1.0/auth/token';

    private const string CLIENT_ASSERTION_TYPE = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';

    /** The fixed JWT `aud` Revolut requires on the client assertion. */
    private const string JWT_AUDIENCE = 'https://revolut.com';

    /**
     * Lifetime of the JWT client assertion. Revolut recommends the shortest
     * window that still lets the token exchange complete, to limit exposure
     * if the assertion leaks — it is signed fresh for, and used by, a single
     * exchange.
     */
    private const int JWT_TTL_SECONDS = 60;

    public function __construct(
        private readonly string $clientId,
        private readonly string $privateKey,
        private readonly string $issuer,
        private readonly ClientInterface $http,
    ) {}

    /**
     * One-time bootstrap: exchange the authorization code obtained from the
     * Revolut Business portal for the initial token set. The returned
     * `refresh_token` is the credential to persist.
     *
     * @return array<string, mixed> the raw token response (`access_token`,
     *                               `refresh_token`, `token_type`, `expires_in`)
     *
     * @throws GuzzleException
     */
    public function exchangeAuthorizationCode(string $code): array
    {
        $tokens = $this->requestToken([
            'grant_type' => 'authorization_code',
            'code' => $code,
        ]);

        if (! isset($tokens['refresh_token']) || ! is_string($tokens['refresh_token'])) {
            throw new RuntimeException('Revolut authorization exchange failed: response did not contain a refresh_token.');
        }

        return $tokens;
    }

    /**
     * Runtime path: swap a stored refresh token for a fresh access token.
     *
     * @return array<string, mixed> the raw token response (`access_token`,
     *                               `token_type`, `expires_in`)
     *
     * @throws GuzzleException
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        return $this->requestToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    /**
     * POSTs a grant to `/auth/token` alongside the signed client assertion and
     * returns the decoded token response.
     *
     * @param  array<string, string>  $grant
     * @return array<string, mixed>
     *
     * @throws GuzzleException
     */
    private function requestToken(array $grant): array
    {
        $response = $this->http->request('POST', self::TOKEN_PATH, [
            'form_params' => [
                ...$grant,
                'client_id' => $this->clientId,
                'client_assertion_type' => self::CLIENT_ASSERTION_TYPE,
                'client_assertion' => $this->buildClientAssertion(),
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);

        if (! is_array($data) || ! isset($data['access_token']) || ! is_string($data['access_token'])) {
            throw new RuntimeException('Revolut authentication failed: response did not contain an access_token.');
        }

        return $data;
    }

    /**
     * Builds and signs the RS256 JWT client assertion Revolut requires to
     * authenticate the token exchange. The claim set — `iss` (the OAuth
     * redirect-URL domain), `sub` (the client id) and `aud` — is exactly what
     * the Business API validates.
     */
    private function buildClientAssertion(): string
    {
        $issuedAt = time();

        $segments = [
            $this->base64UrlEncode($this->jsonEncode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->base64UrlEncode($this->jsonEncode([
                'iss' => $this->issuer,
                'sub' => $this->clientId,
                'aud' => self::JWT_AUDIENCE,
                'iat' => $issuedAt,
                'exp' => $issuedAt + self::JWT_TTL_SECONDS,
            ])),
        ];

        $key = openssl_pkey_get_private($this->privateKey);

        if ($key === false) {
            throw new RuntimeException('Revolut authentication failed: the configured private key is not a valid PEM key.');
        }

        $signature = '';

        if (! openssl_sign(implode('.', $segments), $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Revolut authentication failed: could not sign the JWT client assertion.');
        }

        $segments[] = $this->base64UrlEncode($signature);

        return implode('.', $segments);
    }

    /**
     * @param array<string, mixed> $value
     * @return string
     * @throws JsonException
     */
    private function jsonEncode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
