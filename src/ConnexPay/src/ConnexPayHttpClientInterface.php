<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use GuzzleHttp\Exception\GuzzleException;

interface ConnexPayHttpClientInterface
{
    /**
     * @return array<string, mixed>
     *
     * @throws GuzzleException
     */
    public function post(string $path, array $data): array;

    /**
     * @return array<string, mixed>
     *
     * @throws GuzzleException
     */
    public function put(string $path, array $data): array;
}
