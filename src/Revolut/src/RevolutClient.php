<?php

declare(strict_types=1);

namespace Techork\PaymentService\Revolut;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Override;

/**
 * HTTP client for the Revolut Business REST API
 * (https://developer.revolut.com/docs/business/business-api).
 *
 * Authentication follows the Business API OAuth 2.0 flow
 * (https://developer.revolut.com/docs/guides/manage-accounts/get-started/make-your-first-api-request):
 * before the first API call the client hands its stored `refreshToken` to a
 * {@see RevolutAuthenticator}, which signs a short-lived RS256 JWT client
 * assertion and exchanges the refresh token for a 40-minute `access_token` at
 * `POST /api/1.0/auth/token`. The access token is cached in-memory for the
 * life of the instance — the same self-authenticating pattern the ConnexPay
 * client uses. Callers therefore supply the OAuth credentials, not a bearer
 * token.
 *
 * The long-lived `refreshToken` itself is minted once, out-of-band, from the
 * Revolut Business portal (see {@see RevolutAuthenticator::exchangeAuthorizationCode()}
 * and the package README); it is a stored credential here.
 *
 * There is NO Revolut Sandbox for virtual cards — card creation, updates,
 * termination and sensitive-card-data exist only in Production
 * (https://b2b.revolut.com). The `baseUrl` argument therefore defaults to
 * the production host and exists only so tests / an outbound proxy can
 * redirect the transport; it is not an environment switch.
 */
final class RevolutClient implements RevolutHttpClientInterface
{
    public const string PRODUCTION_BASE_URL = 'https://b2b.revolut.com';

    private ClientInterface $http;

    private RevolutAuthenticator $authenticator;

    private ?string $accessToken = null;

    public function __construct(
        string $clientId,
        string $privateKey,
        private readonly string $refreshToken,
        string $issuer,
        string $baseUrl = self::PRODUCTION_BASE_URL,
        ?ClientInterface $http = null,
    ) {
        $this->http = $http ?? new Client([
            'base_uri' => rtrim($baseUrl, '/').'/',
            'headers' => ['Accept' => 'application/json'],
        ]);

        $this->authenticator = new RevolutAuthenticator($clientId, $privateKey, $issuer, $this->http);
    }

    #[Override]
    public function post(string $path, array $data = []): array
    {
        return $this->send('POST', $path, ['json' => $data]);
    }

    #[Override]
    public function patch(string $path, array $data): array
    {
        return $this->send('PATCH', $path, ['json' => $data]);
    }

    #[Override]
    public function get(string $path): array
    {
        return $this->send('GET', $path);
    }

    #[Override]
    public function delete(string $path): array
    {
        return $this->send('DELETE', $path);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     *
     * @throws GuzzleException
     */
    private function send(string $method, string $path, array $options = []): array
    {
        $this->authenticate();

        $response = $this->http->request($method, ltrim($path, '/'), [
            ...$options,
            'headers' => [
                'Authorization' => 'Bearer '.$this->accessToken,
                'Content-Type' => 'application/json',
            ],
        ]);

        // Terminate / freeze return 204 No Content; treat an empty body as
        // an empty result rather than letting json_decode return null.
        $body = $response->getBody()->getContents();

        return $body === '' ? [] : (json_decode($body, true) ?? []);
    }

    /**
     * Exchanges the refresh token for a fresh access token on first use and
     * caches it for the life of the instance. Revolut access tokens live 40
     * minutes — longer than any single request — so per-instance caching is
     * enough and keeps the transport stateless across processes.
     *
     * @throws GuzzleException
     */
    private function authenticate(): void
    {
        if ($this->accessToken !== null) {
            return;
        }

        $this->accessToken = $this->authenticator->refreshAccessToken($this->refreshToken)['access_token'];
    }
}
