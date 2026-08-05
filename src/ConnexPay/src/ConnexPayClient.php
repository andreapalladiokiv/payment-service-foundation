<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Override;
use RuntimeException;

final class ConnexPayClient implements ConnexPayHttpClientInterface
{
    private const string SANDBOX_BASE_URL = 'https://sandboxsalesapi.connexpay.com';

    private const string PRODUCTION_BASE_URL = 'https://salesapi.connexpay.com';

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
    #[Override]
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
    #[Override]
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
            throw new RuntimeException('ConnexPay authentication failed: '.$e->getMessage(), 0, $e);
        }
    }

    private function baseUrl(): string
    {
        return $this->environment === 'production' ? self::PRODUCTION_BASE_URL : self::SANDBOX_BASE_URL;
    }
}
