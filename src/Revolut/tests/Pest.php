<?php

declare(strict_types=1);

use Techork\PaymentService\Revolut\RevolutGateway;
use Techork\PaymentService\Revolut\RevolutHttpClientInterface;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Gateway\Contract\GatewayCustomerRepository;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;
use Techork\PaymentService\Common\ValueObject\CustomerId;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\Customer;
use Techork\PaymentService\Common\ValueObject\CustomerIdentity;
use Techork\PaymentService\Common\ValueObject\Email;
use Techork\PaymentService\Common\ValueObject\PhoneNumber;

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
    $gateway->configure(new GatewayInfrastructure(
        Mockery::mock(GatewayCredential::class, ['getId' => GatewayId::generate()]),
        Mockery::mock(DecryptInterface::class),
        Mockery::mock(GatewayInstrumentRepository::class, ['find' => null]),
        Mockery::mock(GatewayCustomerRepository::class, ['find' => null]),
        [
            'clientId' => 'client-test',
            'privateKey' => 'key-test',
            'refreshToken' => 'refresh-test',
            'issuer' => 'example.com',
            ...$params,
        ],
    ));

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

/**
 * The payer these tests hand to a command, complete, because a {@see Customer} has no partial
 * form — an id, a person and an address or nothing at all.
 *
 * That completeness is the change worth knowing about here. The id, the identity and the address
 * used to be three optional arguments a caller could supply any subset of, which is how a
 * provider-side customer came to be built out of whatever billing address rode along with the
 * payment. A test that wants to say "no payer" passes null, not a fragment.
 */
function revolutSuiteCustomer(
    ?CustomerId $id = null,
    string $firstName = 'Ada',
    string $lastName = 'Lovelace',
    ?Email $email = null,
    ?PhoneNumber $phone = null,
    ?BillingAddress $address = null,
): Customer {
    return new Customer(
        id: $id ?? CustomerId::fromString('01920000-0000-7000-8000-00000000cafe'),
        identity: new CustomerIdentity($firstName, $lastName, $email, $phone),
        billingAddress: $address ?? new BillingAddress(
            line: '1 Main St',
            city: 'New York',
            country: new Country('US'),
            postalCode: '10001',
        ),
    );
}
