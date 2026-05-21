<?php

declare(strict_types=1);

namespace Techork\PaymentService\Conferma;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

/**
 * HTTP client for the ConfermaPay deployments REST API.
 *
 * Auth flow mirrors the working legacy implementation:
 *   POST {authBaseUrl}/token
 *     Authorization: Basic base64(clientId:clientSecret)
 *     body: grant_type=client_credentials, scope={platformKey}
 *
 * The auth host is **separate** from the API host
 * (sandbox: `assure.cert-confermapay.com` vs `api.cert-confermapay.com`).
 *
 * Token lifetime is read from the response `expires` field; the client
 * keeps a single in-memory token and refreshes once it has expired.
 * Cross-process / cross-tenant caching is the application layer's job
 * (see legacy `CacheManager` keyed per-gateway) — not the SDK's.
 */
final class ConfermaClient implements ConfermaHttpClientInterface
{
    public const string SANDBOX_API_URL = 'https://api.cert-confermapay.com';

    public const string SANDBOX_AUTH_URL = 'https://assure.cert-confermapay.com';

    private ClientInterface $apiHttp;

    private ClientInterface $authHttp;

    private ?string $bearerToken;

    private ?int $bearerExpiresAt = null;

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $platformKey,
        string $apiBaseUrl = self::SANDBOX_API_URL,
        string $authBaseUrl = self::SANDBOX_AUTH_URL,
        ?string $accessToken = null,
        ?ClientInterface $apiHttp = null,
        ?ClientInterface $authHttp = null,
    ) {
        $this->bearerToken = $accessToken;
        $this->apiHttp = $apiHttp ?? new Client([
            'base_uri' => rtrim($apiBaseUrl, '/').'/',
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
        ]);
        $this->authHttp = $authHttp ?? new Client([
            'base_uri' => rtrim($authBaseUrl, '/').'/',
        ]);
    }

    public function post(string $path, array $data = []): array
    {
        $this->authenticate();

        $response = $this->apiHttp->request('POST', ltrim($path, '/'), [
            'json' => $data,
            'headers' => ['Authorization' => 'Bearer '.$this->bearerToken],
        ]);

        return json_decode($response->getBody()->getContents(), true) ?? [];
    }

    public function put(string $path, array $data): array
    {
        $this->authenticate();

        $response = $this->apiHttp->request('PUT', ltrim($path, '/'), [
            'json' => $data,
            'headers' => ['Authorization' => 'Bearer '.$this->bearerToken],
        ]);

        return json_decode($response->getBody()->getContents(), true) ?? [];
    }

    public function get(string $path): array
    {
        $this->authenticate();

        $response = $this->apiHttp->request('GET', ltrim($path, '/'), [
            'headers' => ['Authorization' => 'Bearer '.$this->bearerToken],
        ]);

        return json_decode($response->getBody()->getContents(), true) ?? [];
    }

    private function authenticate(): void
    {
        if ($this->bearerToken !== null && ($this->bearerExpiresAt === null || $this->bearerExpiresAt > time() + 5)) {
            return;
        }

        try {
            $response = $this->authHttp->request('POST', 'token', [
                'auth' => [$this->clientId, $this->clientSecret],
                'form_params' => [
                    'grant_type' => 'client_credentials',
                    'scope' => $this->platformKey,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true) ?? [];
            $this->bearerToken = $data['access_token']
                ?? throw new RuntimeException('ConfermaPay auth response missing access_token field.');
            $this->bearerExpiresAt = $this->resolveExpiry($data['expires'] ?? null);
        } catch (GuzzleException $e) {
            throw new RuntimeException('ConfermaPay authentication failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Conferma's `expires` field is a date-time string (per legacy
     * `Carbon::parse($token->json('expires'))`). Fall back to
     * `expires_in` (seconds) if present, otherwise leave as null and
     * let the next request re-auth on demand.
     */
    private function resolveExpiry(mixed $expires): ?int
    {
        if (is_string($expires) && $expires !== '') {
            $ts = strtotime($expires);

            return $ts === false ? null : $ts;
        }

        if (is_numeric($expires)) {
            return time() + (int) $expires;
        }

        return null;
    }
}
