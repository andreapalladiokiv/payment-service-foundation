<?php

declare(strict_types=1);

use GuzzleHttp\Exception\TransferException;
use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult;
use Techork\PaymentService\Common\ValueObject\Email;
use Techork\PaymentService\Common\ValueObject\ExpiresAt;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\PaymentMethodId;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ECICode;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSStatus;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSVersion;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Common\ValueObject\TokenId;
use Techork\PaymentService\ConnexPay\ConnexPayHttpClientInterface;
use Techork\PaymentService\ConnexPay\Purchase;

/**
 * @param  array<string, mixed>  $command
 * @param  array<string, mixed>  $wiring
 */
function cpPurchase(array $command = [], array $wiring = []): Purchase
{
    return new Purchase(
        cpSettings($wiring['settings'] ?? []),
        cpPlacement($command),
        cpInfrastructure(['instruments' => cpInstruments($wiring['reference'] ?? null)]),
        $wiring['client'] ?? cpHttpClient(),
    );
}

// ──────────────────────────────────────────────
//  payload
// ──────────────────────────────────────────────

it('builds purchase data for credit card', function () {
    $data = cpPurchase([
        'money' => new Money(10000, new Currency('USD')),
        'instrument' => cpCard(),
    ])->payload();

    expect($data['DeviceGuid'])->toBe('device-1')
        ->and($data['Amount'])->toBe(100.00)
        ->and($data['TenderType'])->toBe('Credit')
        ->and($data['Card']['CardNumber'])->toBe('4012000098765439')
        ->and($data['Card']['ExpirationDate'])->toBe('3012')
        ->and($data['Card']['Cvv2'])->toBe('999')
        ->and($data['Card']['CardHolderName'])->toBe('Test User')
        ->and($data['ConnexPayTransaction'])->toBe(['ExpectedPayments' => 1]);
});

it('builds purchase data for token with Guid', function () {
    $data = cpPurchase(
        ['money' => new Money(5000, new Currency('USD')), 'instrument' => cpStoredToken()],
        ['reference' => 'card-guid-abc'],
    )->payload();

    expect($data['Card']['Guid'])->toBe('card-guid-abc')
        ->and($data['Amount'])->toBe(50.00)
        ->and($data['TenderType'])->toBe('Credit')
        ->and($data['ConnexPayTransaction'])->toBe(['ExpectedPayments' => 1]);
});

it('builds purchase data for payment method with Guid', function () {
    $data = cpPurchase(
        ['money' => new Money(3000, new Currency('USD')), 'instrument' => cpStoredPaymentMethod()],
        ['reference' => 'pm-guid-xyz'],
    )->payload();

    expect($data['Card']['Guid'])->toBe('pm-guid-xyz')
        ->and($data['TenderType'])->toBe('Credit')
        ->and($data['ConnexPayTransaction'])->toBe(['ExpectedPayments' => 1]);
});

it('refuses a stored instrument the resolver cannot name', function () {
    cpPurchase(['instrument' => cpStoredToken()])->payload();
})->throws(RuntimeException::class, 'No ConnexPay reference found for token');

it('builds purchase data for cash with Cash tender, ExpectedPayments=5 and Customer', function () {
    $data = cpPurchase([
        'money' => new Money(7500, new Currency('USD')),
        'instrument' => new Cash,
        'billingAddress' => cpBilling(city: 'LA'),
    ])->payload();

    expect($data)->not->toHaveKey('Card')
        ->and($data)->not->toHaveKey('RiskData')
        ->and($data['TenderType'])->toBe('Cash')
        ->and($data['ConnexPayTransaction'])->toBe(['ExpectedPayments' => 5])
        ->and($data['Customer']['FirstName'])->toBe('Test')
        ->and($data['Customer']['LastName'])->toBe('User')
        ->and($data['Customer']['Address1'])->toBe('456 Oak')
        ->and($data['Customer']['Email'])->toBe('buyer@test.com')
        ->and($data['Amount'])->toBe(75.00);
});

it('transliterates the Customer city to ASCII (ConnexPay rejects accents)', function () {
    $data = cpPurchase([
        'money' => new Money(7500, new Currency('USD')),
        'instrument' => new Cash,
        'billingAddress' => cpBilling(city: 'München'),
    ])->payload();

    expect($data['Customer']['City'])->toBe('Munchen');
});

it('forwards clientUniqueId as OrderNumber', function () {
    $data = cpPurchase(['instrument' => new Cash, 'clientUniqueId' => 'order-789'])->payload();

    expect($data['OrderNumber'])->toBe('order-789');
});

it('includes billing address as top-level RiskData', function () {
    $data = cpPurchase([
        'money' => new Money(2000, new Currency('USD')),
        'instrument' => cpCard(cvv: null, holder: 'Test'),
        'billingAddress' => cpBilling(city: 'LA'),
    ])->payload();

    expect($data['Card'])->not->toHaveKey('Customer')
        ->and($data['RiskData']['Name'])->toBe('Test User')
        ->and($data['RiskData']['BillingAddress1'])->toBe('456 Oak')
        ->and($data['RiskData']['BillingPostalCode'])->toBe('90001')
        ->and($data['RiskData']['BillingCountryCode'])->toBe('US')
        ->and($data['RiskData']['Email'])->toBe('buyer@test.com');
});

it('includes StatementDescription when set', function () {
    $data = cpPurchase([
        'money' => new Money(10000, new Currency('USD')),
        'instrument' => cpCard(),
        'statementDescription' => 'ACME Trip 42',
    ])->payload();

    expect($data['StatementDescription'])->toBe('ACME Trip 42');
});

it('includes ThreeDS in Card when threeDS is present', function () {
    $data = cpPurchase([
        'money' => new Money(10000, new Currency('USD')),
        'instrument' => cpCard(),
        'threeDS' => new ThreeDSResult(
            ThreeDSStatus::Successful,
            'cavv-value-abc',
            ECICode::VisaSuccessful,
            'ds-txn-123',
            'acs-txn-456',
            ThreeDSVersion::V220,
        ),
    ])->payload();

    expect($data['Card']['ThreeDS'])->toBe([
        'Cavv' => 'cavv-value-abc',
        'Version' => '2.2.0',
        'DirectoryServerTransactionID' => 'ds-txn-123',
        'AcsTransactionId' => 'acs-txn-456',
        'ECI' => '05',
    ]);
});

it('excludes ThreeDS when threeDS is null', function () {
    expect(cpPurchase(['instrument' => cpCard()])->payload()['Card'])->not->toHaveKey('ThreeDS');
});

it('excludes ThreeDS when Card is null (cash instrument)', function () {
    $data = cpPurchase([
        'money' => new Money(7500, new Currency('USD')),
        'instrument' => new Cash,
        'threeDS' => new ThreeDSResult(
            ThreeDSStatus::Successful,
            'cavv-ignored',
            ECICode::VisaSuccessful,
            'ds-txn-ignored',
            'acs-txn-ignored',
            ThreeDSVersion::V220,
        ),
    ])->payload();

    expect($data)->not->toHaveKey('Card');
});

// ──────────────────────────────────────────────
//  charge
//
//  Payload below is a real ConnexPay sandbox response for POST /api/v1/Sales
//  captured on 2026-05-06.
// ──────────────────────────────────────────────

/**
 * @return array<string, mixed>
 */
function cpSaleApprovedPayload(): array
{
    return [
        'guid' => 'b321a79e-1c9d-43b9-8c68-be306c14234c',
        'status' => 'Transaction - Approved',
        'amount' => 7.5,
        'tenderType' => 'Credit',
        'wasProcessed' => true,
        'processorStatusCode' => 'A0000',
        'processorResponseMessage' => 'Success',
        'card' => [
            'first6' => '401200',
            'last4' => '5439',
            'cardType' => 'Visa',
            'guid' => 'f704f90c-9ba5-41e3-96c0-ebc5eb7a7932',
        ],
        'addressVerificationCode' => '0',
        'cvvVerificationCode' => 'M',
    ];
}

it('sends the sale and reports an approved response as successful', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')
        ->once()
        ->with('/api/v1/sales', Mockery::on(fn (array $d): bool => $d['Amount'] === 100.00))
        ->andReturn(cpSaleApprovedPayload());

    $result = cpPurchase(
        ['money' => new Money(10000, new Currency('USD')), 'instrument' => cpCard()],
        ['client' => $client],
    )->charge();

    expect($result->success)->toBeTrue()
        ->and($result->reference)->toBe('b321a79e-1c9d-43b9-8c68-be306c14234c')
        ->and($result->metadata)->toHaveKey('opening_transaction_reference', 'b321a79e-1c9d-43b9-8c68-be306c14234c');
});

it('exposes AVS and CVV from a real Sale response', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')->once()->andReturn(cpSaleApprovedPayload());

    $result = cpPurchase(['instrument' => cpCard()], ['client' => $client])->charge();

    expect($result->cvcCheck)->toBe(CheckResult::Pass)
        ->and($result->addressLineCheck)->toBe(CheckResult::Unchecked);
});

it('surfaces the incoming transaction code as metadata', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')->once()->andReturn([
        ...cpSaleApprovedPayload(),
        'connexPayTransaction' => ['incomingTransCode' => 'ICT-001'],
    ]);

    $result = cpPurchase(['instrument' => cpCard()], ['client' => $client])->charge();

    expect($result->metadata)->toHaveKey('incoming_transaction_code', 'ICT-001');
});

it('reports a transport failure as a failed authorization', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')->once()->andThrow(new TransferException('Connection refused'));

    $result = cpPurchase(['instrument' => cpCard()], ['client' => $client])->charge();

    expect($result->success)->toBeFalse()
        ->and($result->reference)->toBeNull()
        ->and($result->message)->toBe('Connection refused');
});
