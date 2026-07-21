<?php

declare(strict_types=1);

namespace Techork\PaymentService\Neutrino;

use GuzzleHttp\Exception\GuzzleException;

interface NeutrinoHttpClientInterface
{
    /**
     * Call a Neutrino API endpoint (e.g. `bin-lookup`, `ip-info`) and return
     * the decoded JSON response. Credentials are added by the implementation.
     *
     * @param  array<string, scalar>  $params
     * @return array<string, mixed>
     *
     * @throws GuzzleException
     */
    public function request(string $endpoint, array $params): array;
}
