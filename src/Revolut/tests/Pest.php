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
        'clientId' => 'client-test',
        'privateKey' => 'key-test',
        'refreshToken' => 'refresh-test',
        'issuer' => 'example.com',
        'holderId' => 'holder-uuid',
        ...$params,
    ]);

    if ($client !== null) {
        $gateway->setHttpClient($client);
    }

    return $gateway;
}

/**
 * Generates a throwaway RSA key pair for exercising the JWT client-assertion
 * signing / verification.
 *
 * @return array{0: string, 1: string} [privatePem, publicPem]
 */
function makeRevolutKeyPair(): array
{
    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    openssl_pkey_export($key, $privatePem);
    $publicPem = openssl_pkey_get_details($key)['key'];

    return [$privatePem, $publicPem];
}

function revolutBase64UrlDecode(string $data): string
{
    return (string) base64_decode(strtr($data, '-_', '+/'), true);
}
