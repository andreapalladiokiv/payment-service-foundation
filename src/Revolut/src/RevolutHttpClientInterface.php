<?php

declare(strict_types=1);

namespace Techork\PaymentService\Revolut;

use GuzzleHttp\Exception\GuzzleException;

interface RevolutHttpClientInterface
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
    public function patch(string $path, array $data): array;

    /**
     * @return array<string, mixed>
     *
     * @throws GuzzleException
     */
    public function get(string $path): array;

    /**
     * @return array<string, mixed>
     *
     * @throws GuzzleException
     */
    public function delete(string $path): array;
}
