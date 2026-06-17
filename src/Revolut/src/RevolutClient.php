<?php

declare(strict_types=1);

namespace Techork\PaymentService\Revolut;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

/**
 * HTTP client for the Revolut Business REST API
 * (https://developer.revolut.com/docs/business/business-api).
 *
 * Auth is a Bearer access token sent on every request. Revolut access
 * tokens are short-lived (40 min) and refreshed out-of-band via the
 * JWT client-assertion `/auth/token` flow; that lifecycle is the
 * application layer's responsibility (the legacy stack cached gateway
 * tokens per-tenant in its CacheManager). This SDK is deliberately
 * stateless about token rotation: the factory injects whatever access
 * token is currently valid and the client forwards it.
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

    public function __construct(
        private readonly string $accessToken,
        string $baseUrl = self::PRODUCTION_BASE_URL,
        ?ClientInterface $http = null,
    ) {
        $this->http = $http ?? new Client([
            'base_uri' => rtrim($baseUrl, '/').'/',
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
        ]);
    }

    public function post(string $path, array $data = []): array
    {
        return $this->send('POST', $path, ['json' => $data]);
    }

    public function patch(string $path, array $data): array
    {
        return $this->send('PATCH', $path, ['json' => $data]);
    }

    public function get(string $path): array
    {
        return $this->send('GET', $path);
    }

    public function delete(string $path): array
    {
        return $this->send('DELETE', $path);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $options = []): array
    {
        $response = $this->http->request($method, ltrim($path, '/'), [
            ...$options,
            'headers' => ['Authorization' => 'Bearer '.$this->accessToken],
        ]);

        // Terminate / freeze return 204 No Content; treat an empty body as
        // an empty result rather than letting json_decode return null.
        $body = $response->getBody()->getContents();

        return $body === '' ? [] : (json_decode($body, true) ?? []);
    }
}
