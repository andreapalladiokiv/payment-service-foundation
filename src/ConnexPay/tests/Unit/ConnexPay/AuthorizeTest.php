<?php

declare(strict_types=1);

use GuzzleHttp\Exception\TransferException;
use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ECICode;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSStatus;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSVersion;
use Techork\PaymentService\ConnexPay\Authorize;
use Techork\PaymentService\Gateway\Exception\UnsupportedInstrument;

/**
 * @param  array<string, mixed>  $command
 * @param  array<string, mixed>  $wiring
 */
function cpAuthorize(array $command = [], array $wiring = []): Authorize
{
    return new Authorize(
        cpSettings(['deviceGuid' => 'device-123']),
        cpPlacement($command),
        cpInfrastructure(['instruments' => cpInstruments($wiring['reference'] ?? null)]),
        $wiring['client'] ?? cpHttpClient(),
    );
}

// ──────────────────────────────────────────────
//  payload
// ──────────────────────────────────────────────

it('builds authorize data for credit card', function () {
    $data = cpAuthorize(['money' => new Money(5000, new Currency('USD')), 'instrument' => cpCard()])->payload();

    expect($data['DeviceGuid'])->toBe('device-123')
        ->and($data['Amount'])->toBe(50.00)
        ->and($data['TenderType'])->toBe('Credit')
        ->and($data['Card']['CardNumber'])->toBe('4012000098765439')
        ->and($data['Card']['ExpirationDate'])->toBe('3012')
        ->and($data['Card']['Cvv2'])->toBe('999')
        ->and($data['Card']['CardHolderName'])->toBe('Test User')
        ->and($data)->not->toHaveKey('ConnexPayTransaction');
});

it('builds authorize data for token with Guid', function () {
    $data = cpAuthorize(
        ['money' => new Money(2500, new Currency('USD')), 'instrument' => cpStoredToken()],
        ['reference' => 'card-guid-abc'],
    )->payload();

    expect($data['Card']['Guid'])->toBe('card-guid-abc')
        ->and($data['Amount'])->toBe(25.00)
        ->and($data['TenderType'])->toBe('Credit');
});

it('builds authorize data for payment method with Guid', function () {
    $data = cpAuthorize(
        ['instrument' => cpStoredPaymentMethod()],
        ['reference' => 'pm-guid-xyz'],
    )->payload();

    expect($data['Card']['Guid'])->toBe('pm-guid-xyz')
        ->and($data['TenderType'])->toBe('Credit');
});

it('refuses to build authorize data for cash (cash must go through charge)', function () {
    cpAuthorize(['money' => new Money(3000, new Currency('USD')), 'instrument' => new Cash])->payload();
})->throws(UnsupportedInstrument::class, 'does not accept a "cash" instrument on the "authorize" operation');

it('forwards clientUniqueId as OrderNumber', function () {
    $data = cpAuthorize(['instrument' => cpCard(), 'clientUniqueId' => 'order-456'])->payload();

    expect($data['OrderNumber'])->toBe('order-456');
});

it('includes billing address as top-level RiskData', function () {
    $data = cpAuthorize([
        'instrument' => cpCard(cvv: null, holder: 'Test'),
        'billingAddress' => cpBilling(email: 'test@test.com'),
    ])->payload();

    expect($data['Card'])->not->toHaveKey('Customer')
        ->and($data['RiskData']['Name'])->toBe('Test User')
        ->and($data['RiskData']['BillingAddress1'])->toBe('456 Oak')
        ->and($data['RiskData']['BillingPostalCode'])->toBe('90001')
        ->and($data['RiskData']['BillingCountryCode'])->toBe('US')
        ->and($data['RiskData']['Email'])->toBe('test@test.com');
});

it('includes StatementDescription when set', function () {
    $data = cpAuthorize(['instrument' => cpCard(), 'statementDescription' => 'ACME Trip 42'])->payload();

    expect($data['StatementDescription'])->toBe('ACME Trip 42');
});

it('omits StatementDescription when null or empty', function () {
    expect(cpAuthorize(['instrument' => cpCard(), 'statementDescription' => ''])->payload())
        ->not->toHaveKey('StatementDescription');
});

it('includes ThreeDS in Card when threeDS is present', function () {
    $data = cpAuthorize([
        'instrument' => cpCard(),
        'threeDS' => new ThreeDSResult(
            ThreeDSStatus::Successful,
            'cavv-auth-value',
            ECICode::MastercardSuccessful,
            'ds-txn-auth',
            'acs-txn-auth',
            ThreeDSVersion::V220,
        ),
    ])->payload();

    expect($data['Card']['ThreeDS'])->toBe([
        'Cavv' => 'cavv-auth-value',
        'Version' => '2.2.0',
        'DirectoryServerTransactionID' => 'ds-txn-auth',
        'AcsTransactionId' => 'acs-txn-auth',
        'ECI' => '02',
    ]);
});

it('excludes ThreeDS when threeDS is null', function () {
    expect(cpAuthorize(['instrument' => cpCard()])->payload()['Card'])->not->toHaveKey('ThreeDS');
});

// ──────────────────────────────────────────────
//  authorize
//
//  Payload below is a real ConnexPay sandbox response for POST /api/v1/AuthOnlys
//  captured on 2026-05-06 (test card 4012000098765439).
// ──────────────────────────────────────────────

/**
 * @return array<string, mixed>
 */
function cpAuthApprovedPayload(): array
{
    return [
        'guid' => '1b4ea913-8aa8-4e84-a184-9d96e9adec4e',
        'status' => 'Transaction - Approved',
        'amount' => 5.0,
        'deviceGuid' => 'd4d1267d-d619-4704-86cd-a9c6c3c1ec2c',
        'processorStatusCode' => 'A0000',
        'processorResponseMessage' => 'Success',
        'wasProcessed' => true,
        'card' => [
            'first6' => '401200',
            'last4' => '5439',
            'cardHolderName' => 'Test User',
            'cardType' => 'Visa',
            'expirationDate' => '2030-12',
            'guid' => '44d491d4-2c23-4493-9d95-eb7301c0afda',
        ],
        'addressVerificationCode' => '0',
        'cvvVerificationCode' => 'M',
    ];
}

it('sends the auth-only and reports an approved response as successful', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')
        ->once()
        ->with('/api/v1/authonlys', Mockery::on(fn (array $d): bool => $d['TenderType'] === 'Credit'))
        ->andReturn(cpAuthApprovedPayload());

    $result = cpAuthorize(['instrument' => cpCard()], ['client' => $client])->authorize();

    expect($result->success)->toBeTrue()
        ->and($result->reference)->toBe('1b4ea913-8aa8-4e84-a184-9d96e9adec4e')
        ->and($result->metadata)->toHaveKey('opening_transaction_reference', '1b4ea913-8aa8-4e84-a184-9d96e9adec4e');
});

it('exposes AVS and CVV from a real AuthOnly response', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')->once()->andReturn(cpAuthApprovedPayload());

    $result = cpAuthorize(['instrument' => cpCard()], ['client' => $client])->authorize();

    expect($result->addressLineCheck)->toBe(CheckResult::Unchecked)
        ->and($result->postalCodeCheck)->toBe(CheckResult::Unchecked)
        ->and($result->cvcCheck)->toBe(CheckResult::Pass);
});

it('reports a declined auth-only with the processor message', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')->once()->andReturn([
        'wasProcessed' => false,
        'guid' => null,
        'processorResponseMessage' => 'Do not honor',
    ]);

    $result = cpAuthorize(['instrument' => cpCard()], ['client' => $client])->authorize();

    expect($result->success)->toBeFalse()
        ->and($result->reference)->toBeNull()
        ->and($result->message)->toBe('Do not honor');
});

it('reports a transport failure as a failed authorization', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')->once()->andThrow(new TransferException('Connection refused'));

    $result = cpAuthorize(['instrument' => cpCard()], ['client' => $client])->authorize();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('Connection refused');
});
