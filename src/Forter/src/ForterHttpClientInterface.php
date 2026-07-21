<?php

declare(strict_types=1);

namespace Techork\PaymentService\Forter;

use GuzzleHttp\Exception\GuzzleException;

interface ForterHttpClientInterface
{
    /**
     * POST an order to Forter's validation endpoint (`/orders/{orderId}`) and
     * return the decoded response.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws GuzzleException
     */
    public function postOrder(string $orderId, array $body): array;
}
