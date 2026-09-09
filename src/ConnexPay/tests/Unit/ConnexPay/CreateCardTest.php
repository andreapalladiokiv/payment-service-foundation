<?php

declare(strict_types=1);

use GuzzleHttp\Exception\TransferException;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Customer;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\Email;
use Techork\PaymentService\Common\ValueObject\State;
use Techork\PaymentService\ConnexPay\CreateCard;

/**
 * @param  array<string, mixed>  $command
 * @param  array<string, mixed>  $wiring
 */
function cpCreateCard(array $command = [], array $wiring = []): CreateCard
{
    return new CreateCard(
        cpSettings(['deviceGuid' => $wiring['deviceGuid'] ?? 'device-abc']),
        cpVault($command),
        cpInfrastructure(),
        $wiring['client'] ?? cpHttpClient(),
        $wiring['threeDS'] ?? null,
    );
}

function cpVerifyBilling(): BillingAddress
{
    return new BillingAddress(
        line: '456 Oak Ave',
        city: 'Tempe',
        country: new Country('US'),
        postalCode: '85284',
        state: new State('AZ'),
    );
}

// ──────────────────────────────────────────────
//  payload
// ──────────────────────────────────────────────

it('builds verify data for credit card', function () {
    $card = new CreditCard(
        Number::fromNumber('4012000098765439', cpEncrypter()),
        Expiration::fromMonthAndYear(6, 2028),
        new Holder('Jane Doe'),
        Cvc::fromCvc('999', cpEncrypter()),
    );

    $data = cpCreateCard(['instrument' => $card])->payload();

    expect($data['DeviceGuid'])->toBe('device-abc')
        ->and($data['Card']['CardNumber'])->toBe('4012000098765439')
        ->and($data['Card']['ExpirationDate'])->toBe('2806')
        ->and($data['Card']['Cvv2'])->toBe('999')
        ->and($data['Card']['CardHolderName'])->toBe('Jane Doe');
});

it('omits CVV when empty and forwards empty CardHolderName', function () {
    $data = cpCreateCard(['instrument' => cpCard(cvv: null, holder: '')])->payload();

    expect($data['Card'])->not->toHaveKey('Cvv2')
        ->and($data['Card']['CardHolderName'])->toBe('');
});

it('includes the Customer block when a customer is named', function () {
    $data = cpCreateCard(['instrument' => cpCard(), 'customer' => connexPaySuiteCustomer(
        firstName: 'Jane',
        lastName: 'Doe',
        email: new Email('jane@test.com'),
        address: cpVerifyBilling(),
    )])->payload();

    expect($data['Card']['Customer'])->toBe([
        'FirstName' => 'Jane',
        'LastName' => 'Doe',
        'Phone' => null,
        'City' => 'Tempe',
        'State' => 'AZ',
        'Country' => 'US',
        'Email' => 'jane@test.com',
        'Address1' => '456 Oak Ave',
        'Address2' => '',
        'Zip' => '85284',
    ]);
});

it('omits Customer block when billingAddress is not provided', function () {
    expect(cpCreateCard(['instrument' => cpCard()])->payload()['Card'])->not->toHaveKey('Customer');
});

it('throws on token instrument', function () {
    cpCreateCard(['instrument' => cpStoredToken()])->payload();
})->throws(RuntimeException::class, 'Token does not support tokenization');

it('throws on cash instrument', function () {
    cpCreateCard(['instrument' => new Cash])->payload();
})->throws(RuntimeException::class, 'ConnexPay does not support cash');

// ──────────────────────────────────────────────
//  tokenize
//
//  The mapping is based on a real sandbox call on 2026-05-06:
//  card.guid → the token reference, card.customer.guid → the customer.
// ──────────────────────────────────────────────

it('exposes the card guid as the registration reference', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')
        ->once()
        ->with('/api/v1/verify', Mockery::on(fn (array $d): bool => isset($d['Card']['CardNumber'])))
        ->andReturn([
            'wasProcessed' => true,
            'status' => 'Transaction - Approved',
            'card' => ['guid' => '6b028ba0-cec1-433a-bb25-d738113d2472'],
        ]);

    $result = cpCreateCard(['instrument' => cpCard()], ['client' => $client])->tokenize();

    expect($result->success)->toBeTrue()
        ->and($result->reference)->toBe('6b028ba0-cec1-433a-bb25-d738113d2472');
});

it('exposes the customer guid when billing was sent', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')->once()->andReturn([
        'wasProcessed' => true,
        'status' => 'Transaction - Approved',
        'card' => [
            'guid' => '98f9b6e9-a0c8-4da2-b762-25798632e490',
            'customer' => ['guid' => 'babae5fa-7bd3-45eb-bfc5-84b36eebcf3d'],
        ],
    ]);

    $result = cpCreateCard(
        ['instrument' => cpCard(), 'billingAddress' => cpVerifyBilling()],
        ['client' => $client],
    )->tokenize();

    expect($result->customerReference)->toBe('babae5fa-7bd3-45eb-bfc5-84b36eebcf3d');
});

it('returns no customer reference when billing was not sent', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')->once()->andReturn([
        'wasProcessed' => true,
        'card' => ['guid' => 'some-card-guid'],
    ]);

    expect(cpCreateCard(['instrument' => cpCard()], ['client' => $client])->tokenize()->customerReference)
        ->toBeNull();
});

it('marks failure when wasProcessed is false', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')->once()->andReturn([
        'wasProcessed' => false,
        'processorResponseMessage' => 'Connection refused',
    ]);

    $result = cpCreateCard(['instrument' => cpCard()], ['client' => $client])->tokenize();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('Connection refused');
});

it('returns a failed registration on a transport error', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')->once()->andThrow(new TransferException('Network error'));

    expect(cpCreateCard(['instrument' => cpCard()], ['client' => $client])->tokenize()->message)
        ->toBe('Network error');
});

/*
 * Two tests are gone and cannot come back in this shape.
 *
 * `CreateCardResponse` implemented `CustomerReferenceProvider` and one test asserted the
 * interface; a {@see \Techork\PaymentService\Gateway\Contract\RegistrationResult} carries
 * `customerReference` as a field, so there is no interface left to be an instance of — what the
 * test was really checking is the assertion above it.
 *
 * The other constructed the response from an already-flattened array to pin the flattening. The
 * operation flattens and maps in one step now, so the intermediate shape it inspected does not
 * exist; the sandbox bodies above exercise the same mapping from the real input instead.
 */

/**
 * Only reachable by handing the operation an attestation directly: {@see \Techork\PaymentService\Gateway\Command\VaultCommand}
 * has no field for one, so the gateway can never supply it. Kept because the wire behaviour is
 * real — ConnexPay accepts `Card.ThreeDS` on /verify — and dropping the code would quietly lose
 * it the day the command grows the field.
 */
it('forwards ThreeDS when one is handed to it', function () {
    $data = cpCreateCard(['instrument' => cpCard()], ['threeDS' => cpThreeDS('cavv-tokenize')])->payload();

    expect($data['Card']['ThreeDS']['Cavv'])->toBe('cavv-tokenize');
});

it('omits ThreeDS when none was handed to it', function () {
    expect(cpCreateCard(['instrument' => cpCard()])->payload()['Card'])->not->toHaveKey('ThreeDS');
});
