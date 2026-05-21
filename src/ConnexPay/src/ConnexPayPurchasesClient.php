<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

/**
 * HTTP client for ConnexPay Purchases API (virtual card issuance).
 *
 * Separate from {@see ConnexPayClient} — different base URL and auth.
 */
final class ConnexPayPurchasesClient implements ConnexPayHttpClientInterface
{
    private const string SANDBOX_BASE_URL = 'https://sandboxpurchasesapi.connexpay.com';

    private const string PRODUCTION_BASE_URL = 'https://purchasesapi.connexpay.com';

    private Client $http;

    private ?string $bearerToken = null;

    public function __construct(
        private readonly string $username,
        private readonly string $password,
        private readonly string $environment = 'sandbox',
    ) {
        $this->http = new Client([
            'base_uri' => $this->baseUrl(),
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
        ]);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws GuzzleException
     */
    public function post(string $path, array $data): array
    {
        $this->authenticate();

        $response = $this->http->post($path, [
            'json' => $data,
            'headers' => ['Authorization' => 'Bearer '.$this->bearerToken],
        ]);

        return json_decode($response->getBody()->getContents(), true) ?? [];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws GuzzleException
     */
    public function put(string $path, array $data): array
    {
        $this->authenticate();

        $response = $this->http->put($path, [
            'json' => $data,
            'headers' => ['Authorization' => 'Bearer '.$this->bearerToken],
        ]);

        return json_decode($response->getBody()->getContents(), true) ?? [];
    }

    private function authenticate(): void
    {
        if ($this->bearerToken !== null) {
            return;
        }

        try {
            $response = $this->http->post('/api/v1/token', [
                'form_params' => [
                    'grant_type' => 'password',
                    'username' => $this->username,
                    'password' => $this->password,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $this->bearerToken = $data['access_token'];
        } catch (GuzzleException $e) {
            throw new RuntimeException('ConnexPay Purchases API authentication failed: '.$e->getMessage(), 0, $e);
        }
    }

    private function baseUrl(): string
    {
        return $this->environment === 'production' ? self::PRODUCTION_BASE_URL : self::SANDBOX_BASE_URL;
    }
}
