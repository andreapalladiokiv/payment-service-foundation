<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\Contract\EncryptInterface;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\Email;
use Techork\PaymentService\Common\ValueObject\ExpiresAt;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\PaymentMethodId;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Common\ValueObject\TokenId;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ECICode;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSStatus;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSVersion;
use Techork\PaymentService\ConnexPay\PurchaseRequest;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

function purchaseCpCredential(): GatewayCredential
{
    return new readonly class implements GatewayCredential {
        public function getId(): GatewayId { return GatewayId::generate(); }
        public function getGatewayName(): string { return 'ConnexPay'; }
        public function getCredentials(): array { return []; }
    };
}

function purchaseCpEnc(): EncryptInterface
{
    return new class implements EncryptInterface { public function encrypt(string $d): string { return $d; } };
}

function purchaseCpDec(): DecryptInterface
{
    return new class implements DecryptInterface { public function decrypt(string $d): string { return $d; } };
}

it('builds purchase data for credit card', function () {
    $card = new CreditCard(
        Number::fromNumber('4012000098765439', purchaseCpEnc()),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Test User'),
        Cvc::fromCvc('999', purchaseCpEnc()),
    );

    $request = new PurchaseRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(10000, new Currency('USD')),
        'instrument' => $card,
        'gateway' => purchaseCpCredential(),
        'decrypter' => purchaseCpDec(),
        'deviceGuid' => 'device-1',
    ]);

    $data = $request->getData();

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
    $token = new Token(
        TokenId::generate(),
        new CreditCard(new Number('401200', '5439', CardBrand::Visa), Expiration::fromMonthAndYear(12, 2030), new Holder('T'), new Cvc),
        ExpiresAt::fromDateTime(new DateTimeImmutable('+1 hour')),
    );

    $ref = Mockery::mock(GatewayInstrumentRepository::class);
    $ref->shouldReceive('find')->andReturn('card-guid-abc');

    $request = new PurchaseRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(5000, new Currency('USD')),
        'instrument' => $token,
        'gateway' => purchaseCpCredential(),
        'decrypter' => purchaseCpDec(),
        'referenceResolver' => $ref,
        'deviceGuid' => 'device-1',
    ]);

    $data = $request->getData();

    expect($data['Card']['Guid'])->toBe('card-guid-abc')
        ->and($data['Amount'])->toBe(50.00)
        ->and($data['TenderType'])->toBe('Credit')
        ->and($data['ConnexPayTransaction'])->toBe(['ExpectedPayments' => 1]);
});

it('builds purchase data for payment method with Guid', function () {
    $pm = new PaymentMethod(
        PaymentMethodId::generate(),
        new CreditCard(new Number('401200', '5439', CardBrand::Visa), Expiration::fromMonthAndYear(12, 2030), new Holder('T'), new Cvc),
        new BillingAddress('Test', 'User', '1 St', 'NYC', new Country('US'), '10001'),
    );

    $ref = Mockery::mock(GatewayInstrumentRepository::class);
    $ref->shouldReceive('find')->andReturn('pm-guid-xyz');

    $request = new PurchaseRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(3000, new Currency('USD')),
        'instrument' => $pm,
        'gateway' => purchaseCpCredential(),
        'decrypter' => purchaseCpDec(),
        'referenceResolver' => $ref,
        'deviceGuid' => 'device-1',
    ]);

    $data = $request->getData();

    expect($data['Card']['Guid'])->toBe('pm-guid-xyz')
        ->and($data['TenderType'])->toBe('Credit')
        ->and($data['ConnexPayTransaction'])->toBe(['ExpectedPayments' => 1]);
});

it('builds purchase data for cash with Cash tender, ExpectedPayments=5 and Customer', function () {
    $billing = new BillingAddress('Test', 'User', '456 Oak', 'LA', new Country('US'), '90001', email: new Email('buyer@test.com'));

    $request = new PurchaseRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(7500, new Currency('USD')),
        'instrument' => new Cash,
        'gateway' => purchaseCpCredential(),
        'decrypter' => purchaseCpDec(),
        'deviceGuid' => 'device-1',
        'billingAddress' => $billing,
    ]);

    $data = $request->getData();

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
    $billing = new BillingAddress('Test', 'User', '456 Oak', 'München', new Country('DE'), '80331');

    $request = new PurchaseRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(7500, new Currency('USD')),
        'instrument' => new Cash,
        'gateway' => purchaseCpCredential(),
        'decrypter' => purchaseCpDec(),
        'deviceGuid' => 'device-1',
        'billingAddress' => $billing,
    ]);

    expect($request->getData()['Customer']['City'])->toBe('Munchen');
});

it('forwards clientUniqueId as OrderNumber', function () {
    $request = new PurchaseRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(1000, new Currency('USD')),
        'instrument' => new Cash,
        'gateway' => purchaseCpCredential(),
        'decrypter' => purchaseCpDec(),
        'deviceGuid' => 'device-1',
        'clientUniqueId' => 'order-789',
    ]);

    $data = $request->getData();

    expect($data['OrderNumber'])->toBe('order-789');
});

it('includes billing address as top-level RiskData', function () {
    $card = new CreditCard(
        Number::fromNumber('4012000098765439', purchaseCpEnc()),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Test'),
        new Cvc,
    );

    $billing = new BillingAddress('Test', 'User', '456 Oak', 'LA', new Country('US'), '90001', email: new Email('buyer@test.com'));

    $request = new PurchaseRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(2000, new Currency('USD')),
        'instrument' => $card,
        'gateway' => purchaseCpCredential(),
        'decrypter' => purchaseCpDec(),
        'billingAddress' => $billing,
        'deviceGuid' => 'device-1',
    ]);

    $data = $request->getData();

    expect($data['Card'])->not->toHaveKey('Customer')
        ->and($data['RiskData']['Name'])->toBe('Test User')
        ->and($data['RiskData']['BillingAddress1'])->toBe('456 Oak')
        ->and($data['RiskData']['BillingPostalCode'])->toBe('90001')
        ->and($data['RiskData']['BillingCountryCode'])->toBe('US')
        ->and($data['RiskData']['Email'])->toBe('buyer@test.com');
});

it('includes StatementDescription when set', function () {
    $card = new CreditCard(
        Number::fromNumber('4012000098765439', purchaseCpEnc()),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Test User'),
        Cvc::fromCvc('999', purchaseCpEnc()),
    );

    $request = new PurchaseRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(10000, new Currency('USD')),
        'instrument' => $card,
        'gateway' => purchaseCpCredential(),
        'decrypter' => purchaseCpDec(),
        'deviceGuid' => 'device-1',
        'statementDescription' => 'ACME Trip 42',
    ]);

    expect($request->getData()['StatementDescription'])->toBe('ACME Trip 42');
});

it('includes ThreeDS in Card when threeDS is present', function () {
    $card = new CreditCard(
        Number::fromNumber('4012000098765439', purchaseCpEnc()),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Test User'),
        Cvc::fromCvc('999', purchaseCpEnc()),
    );

    $threeDS = new ThreeDSResult(
        ThreeDSStatus::Successful,
        'cavv-value-abc',
        ECICode::VisaSuccessful,
        'ds-txn-123',
        'acs-txn-456',
        ThreeDSVersion::V220,
    );

    $request = new PurchaseRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(10000, new Currency('USD')),
        'instrument' => $card,
        'gateway' => purchaseCpCredential(),
        'decrypter' => purchaseCpDec(),
        'deviceGuid' => 'device-1',
        'threeDS' => $threeDS,
    ]);

    $data = $request->getData();

    expect($data['Card']['ThreeDS'])->toBe([
        'Cavv' => 'cavv-value-abc',
        'Version' => '2.2.0',
        'DirectoryServerTransactionID' => 'ds-txn-123',
        'AcsTransactionId' => 'acs-txn-456',
        'ECI' => '05',
    ]);
});

it('excludes ThreeDS when threeDS is null', function () {
    $card = new CreditCard(
        Number::fromNumber('4012000098765439', purchaseCpEnc()),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Test User'),
        Cvc::fromCvc('999', purchaseCpEnc()),
    );

    $request = new PurchaseRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(10000, new Currency('USD')),
        'instrument' => $card,
        'gateway' => purchaseCpCredential(),
        'decrypter' => purchaseCpDec(),
        'deviceGuid' => 'device-1',
    ]);

    $data = $request->getData();

    expect($data['Card'])->not->toHaveKey('ThreeDS');
});

it('excludes ThreeDS when Card is null (cash instrument)', function () {
    $threeDS = new ThreeDSResult(
        ThreeDSStatus::Successful,
        'cavv-ignored',
        ECICode::VisaSuccessful,
        'ds-txn-ignored',
        'acs-txn-ignored',
        ThreeDSVersion::V220,
    );

    $request = new PurchaseRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(7500, new Currency('USD')),
        'instrument' => new Cash,
        'gateway' => purchaseCpCredential(),
        'decrypter' => purchaseCpDec(),
        'deviceGuid' => 'device-1',
        'threeDS' => $threeDS,
    ]);

    $data = $request->getData();

    expect($data)->not->toHaveKey('Card');
});
