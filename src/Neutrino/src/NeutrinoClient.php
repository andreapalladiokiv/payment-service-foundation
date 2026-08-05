<?php

declare(strict_types=1);

namespace Techork\PaymentService\Neutrino;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Override;

/**
 * HTTP client for the Neutrino API (https://www.neutrinoapi.com). Auth is a
 * `user-id` + `api-key` pair sent as form parameters on every call, matching
 * the legacy backoffice integration.
 *
 * Transport-only; the {@see NeutrinoCardIntelligenceProvider} and
 * {@see NeutrinoIpIntelligenceProvider} shape requests and map responses.
 */
final class NeutrinoClient implements NeutrinoHttpClientInterface
{
    public const string BASE_URL = 'https://neutrinoapi.net';

    private ClientInterface $http;

    public function __construct(
        private readonly string $userId,
        private readonly string $apiKey,
        string $baseUrl = self::BASE_URL,
        ?ClientInterface $http = null,
    ) {
        $this->http = $http ?? new Client([
            'base_uri' => rtrim($baseUrl, '/').'/',
            'headers' => ['Accept' => 'application/json'],
        ]);
    }

    #[Override]
    public function request(string $endpoint, array $params): array
    {
        $response = $this->http->request('POST', ltrim($endpoint, '/'), [
            'form_params' => [...$params, 'user-id' => $this->userId, 'api-key' => $this->apiKey],
        ]);

        $content = $response->getBody()->getContents();

        return $content === '' ? [] : (json_decode($content, true) ?? []);
    }
}
