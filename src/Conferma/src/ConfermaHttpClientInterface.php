<?php

declare(strict_types=1);

namespace Techork\PaymentService\Conferma;

use GuzzleHttp\Exception\GuzzleException;

interface ConfermaHttpClientInterface
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws GuzzleException
     */
    public function post(string $path, array $data = []): array;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws GuzzleException
     */
    public function put(string $path, array $data): array;

    /**
     * @return array<string, mixed>
     *
     * @throws GuzzleException
     */
    public function get(string $path): array;
}
