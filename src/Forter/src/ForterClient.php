<?php

declare(strict_types=1);

namespace Techork\PaymentService\Forter;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

/**
 * HTTP client for the Forter REST API (https://api.forter.com).
 *
 * Auth follows Forter's convention: the secret key is the HTTP Basic username
 * with an empty password (`base64(secretKey:)`), plus the `api-version`
 * header. The `baseUrl` embeds the API version (e.g. `.../v2`) and defaults to
 * production; tests / an outbound proxy inject their own transport via `$http`.
 *
 * This client is transport-only — request shaping lives in
 * {@see ForterRequestMapper} and decisioning in
 * {@see ForterFraudScreeningProvider}.
 */
final class ForterClient implements ForterHttpClientInterface
{
    public const string PRODUCTION_BASE_URL = 'https://api.forter.com/v2';

    public const string API_VERSION = '2.2';

    private ClientInterface $http;

    public function __construct(
        private readonly string $secretKey,
        string $baseUrl = self::PRODUCTION_BASE_URL,
        private readonly ?string $siteId = null,
        ?ClientInterface $http = null,
    ) {
        $this->http = $http ?? new Client([
            'base_uri' => rtrim($baseUrl, '/').'/',
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
        ]);
    }

    public function postOrder(string $orderId, array $body): array
    {
        $headers = [
            'Authorization' => 'Basic '.base64_encode($this->secretKey.':'),
            'api-version' => self::API_VERSION,
        ];

        if ($this->siteId !== null) {
            $headers['x-forter-siteid'] = $this->siteId;
        }

        $response = $this->http->request('POST', 'orders/'.rawurlencode($orderId), [
            'json' => $body,
            'headers' => $headers,
        ]);

        $content = $response->getBody()->getContents();

        return $content === '' ? [] : (json_decode($content, true) ?? []);
    }
}
