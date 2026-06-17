<?php

declare(strict_types=1);

use Techork\PaymentService\Revolut\RevolutGateway;
use Techork\PaymentService\Revolut\RevolutHttpClientInterface;

/**
 * Builds a RevolutGateway initialised with test credentials. When a fake /
 * mocked HTTP client is supplied it replaces the real one built during
 * initialize(), so requests created by the gateway send through the mock.
 *
 * @param  array<string, mixed>  $params
 */
function makeRevolutGateway(?RevolutHttpClientInterface $client = null, array $params = []): RevolutGateway
{
    $gateway = new RevolutGateway;
    $gateway->initialize([
        'accessToken' => 'tok_test',
        'holderId' => 'holder-uuid',
        ...$params,
    ]);

    if ($client !== null) {
        $gateway->setHttpClient($client);
    }

    return $gateway;
}
