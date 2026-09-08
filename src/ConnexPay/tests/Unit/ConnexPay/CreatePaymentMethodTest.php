<?php

declare(strict_types=1);

use GuzzleHttp\Exception\TransferException;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\ConnexPay\ConnexPayHttpClientInterface;
use Techork\PaymentService\ConnexPay\CreatePaymentMethod;

/**
 * @param  array<string, mixed>  $command
 * @param  array<string, mixed>  $wiring
 */
function cpRegister(array $command = [], array $wiring = []): CreatePaymentMethod
{
    return new CreatePaymentMethod(
        cpSettings(['deviceGuid' => 'device-9']),
        cpVault($command),
        cpInfrastructure(['instruments' => cpInstruments($wiring['reference'] ?? null)]),
        $wiring['client'] ?? cpHttpClient(),
        $wiring['threeDS'] ?? null,
    );
}

it('builds a verify payload from the token reference with the cardholder Customer', function () {
    $data = cpRegister(
        [
            'instrument' => cpStoredToken(),
            'billingAddress' => new BillingAddress('Test', 'User', '1 St', 'NYC', new Country('US'), '10001'),
        ],
        ['reference' => 'card-guid-abc'],
    )->payload();

    expect($data['DeviceGuid'])->toBe('device-9')
        ->and($data['Card']['Guid'])->toBe('card-guid-abc')
        ->and($data['Card']['Customer']['FirstName'])->toBe('Test')
        ->and($data['Card']['Customer']['City'])->toBe('NYC');
});

it('builds a verify payload from a raw credit card', function () {
    $data = cpRegister(['instrument' => cpCard(cvv: null)])->payload();

    expect($data['Card']['CardNumber'])->toBe('4012000098765439')
        ->and($data['Card']['ExpirationDate'])->toBe('3012')
        ->and($data['Card']['CardHolderName'])->toBe('Test User');
});

it('sends verify and maps the verified card guid and customer guid', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')
        ->once()
        ->with('/api/v1/verify', Mockery::on(fn (array $d): bool => $d['Card']['Guid'] === 'card-guid-abc'))
        ->andReturn([
            'wasProcessed' => true,
            'status' => 'Transaction - Approved',
            'addressVerificationCode' => 'Y',
            'cvvVerificationCode' => 'M',
            'card' => [
                'guid' => 'verified-guid-1',
                'customer' => ['guid' => 'customer-guid-1'],
            ],
        ]);

    $result = cpRegister(
        ['instrument' => cpStoredToken()],
        ['reference' => 'card-guid-abc', 'client' => $client],
    )->register();

    expect($result->success)->toBeTrue()
        ->and($result->reference)->toBe('verified-guid-1')
        ->and($result->customerReference)->toBe('customer-guid-1')
        // Unlike a tokenization, a registration reports the verification letters — re-verifying
        // the stored card against the cardholder is what it is for.
        ->and($result->cvcCheck)->not->toBeNull()
        ->and($result->addressLineCheck)->not->toBeNull();
});

it('throws on cash instrument', function () {
    cpRegister(['instrument' => new Cash])->payload();
})->throws(RuntimeException::class, 'Cash cannot be stored');

it('throws on payment method instrument', function () {
    cpRegister(['instrument' => cpStoredPaymentMethod()])->payload();
})->throws(RuntimeException::class, 'PaymentMethod cannot be re-stored');

it('returns a failed registration on a transport error', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')->once()->andThrow(new TransferException('Network error'));

    expect(cpRegister(['instrument' => cpCard()], ['client' => $client])->register()->message)
        ->toBe('Network error');
});

/**
 * Reachable only by handing the operation an attestation directly — see the same note on
 * {@see \Techork\PaymentService\ConnexPay\CreatePaymentMethod::__construct()}. ConnexPay accepts
 * `Card.ThreeDS` on /verify, so a registration that WAS authenticated must forward it or the
 * step-up is performed and then discarded.
 */
it('forwards ThreeDS when one is handed to it', function () {
    $data = cpRegister(['instrument' => cpCard()], ['threeDS' => cpThreeDS('cavv-registration')])->payload();

    expect($data['Card']['ThreeDS'])->toBe([
        'Cavv' => 'cavv-registration',
        'Version' => '2.2.0',
        'DirectoryServerTransactionID' => 'ds-txn-123',
        'AcsTransactionId' => 'acs-txn-456',
        'ECI' => '02',
    ]);
});

it('omits ThreeDS from a registration that was not authenticated', function () {
    expect(cpRegister(['instrument' => cpCard()])->payload()['Card'])->not->toHaveKey('ThreeDS');
});
