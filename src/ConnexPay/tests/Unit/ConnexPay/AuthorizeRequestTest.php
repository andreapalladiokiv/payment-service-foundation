<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
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
use Techork\PaymentService\ConnexPay\AuthorizeRequest;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;

it('builds authorize data for credit card', function () {
    $card = new CreditCard(
        Number::fromNumber('4012000098765439', cpEncrypter()),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Test User'),
        Cvc::fromCvc('999', cpEncrypter()),
    );

    $request = new AuthorizeRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(5000, new Currency('USD')),
        'instrument' => $card,
        'gateway' => cpCredential(),
        'decrypter' => cpDecrypter(),
        'deviceGuid' => 'device-123',
    ]);

    $data = $request->getData();

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
    $token = new Token(
        TokenId::generate(),
        new CreditCard(new Number('401200', '5439', CardBrand::Visa), Expiration::fromMonthAndYear(12, 2030), new Holder('T'), new Cvc),
        ExpiresAt::fromDateTime(new DateTimeImmutable('+1 hour')),
    );

    $ref = Mockery::mock(GatewayInstrumentRepository::class);
    $ref->shouldReceive('find')->andReturn('card-guid-abc');

    $request = new AuthorizeRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(2500, new Currency('USD')),
        'instrument' => $token,
        'gateway' => cpCredential(),
        'decrypter' => cpDecrypter(),
        'referenceResolver' => $ref,
        'deviceGuid' => 'device-123',
    ]);

    $data = $request->getData();

    expect($data['Card']['Guid'])->toBe('card-guid-abc')
        ->and($data['Amount'])->toBe(25.00)
        ->and($data['TenderType'])->toBe('Credit');
});

it('builds authorize data for payment method with Guid', function () {
    $pm = new PaymentMethod(
        PaymentMethodId::generate(),
        new CreditCard(new Number('401200', '5439', CardBrand::Visa), Expiration::fromMonthAndYear(12, 2030), new Holder('T'), new Cvc),
        new BillingAddress('Test', 'User', '1 St', 'NYC', new Country('US'), '10001'),
    );

    $ref = Mockery::mock(GatewayInstrumentRepository::class);
    $ref->shouldReceive('find')->andReturn('pm-guid-xyz');

    $request = new AuthorizeRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(1000, new Currency('USD')),
        'instrument' => $pm,
        'gateway' => cpCredential(),
        'decrypter' => cpDecrypter(),
        'referenceResolver' => $ref,
        'deviceGuid' => 'device-123',
    ]);

    $data = $request->getData();

    expect($data['Card']['Guid'])->toBe('pm-guid-xyz')
        ->and($data['TenderType'])->toBe('Credit');
});

it('refuses to build authorize data for cash (cash must go through charge)', function () {
    $request = new AuthorizeRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(3000, new Currency('USD')),
        'instrument' => new Cash,
        'gateway' => cpCredential(),
        'decrypter' => cpDecrypter(),
        'deviceGuid' => 'device-123',
    ]);

    $request->getData();
})->throws(RuntimeException::class, 'does not support cash');

it('omits OrderNumber even when clientUniqueId is set', function () {
    $card = new CreditCard(
        Number::fromNumber('4012000098765439', cpEncrypter()),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Test User'),
        Cvc::fromCvc('999', cpEncrypter()),
    );

    $request = new AuthorizeRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(1000, new Currency('USD')),
        'instrument' => $card,
        'gateway' => cpCredential(),
        'decrypter' => cpDecrypter(),
        'deviceGuid' => 'device-123',
        'clientUniqueId' => 'order-456',
    ]);

    $data = $request->getData();

    expect($data)->not->toHaveKey('OrderNumber');
});

it('includes billing address as top-level RiskData', function () {
    $card = new CreditCard(
        Number::fromNumber('4012000098765439', cpEncrypter()),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Test'),
        new Cvc,
    );

    $billing = new BillingAddress('Test', 'User', '123 Main', 'NYC', new Country('US'), '10001', email: new Email('test@test.com'));

    $request = new AuthorizeRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(1000, new Currency('USD')),
        'instrument' => $card,
        'gateway' => cpCredential(),
        'decrypter' => cpDecrypter(),
        'billingAddress' => $billing,
        'deviceGuid' => 'device-123',
    ]);

    $data = $request->getData();

    expect($data['Card'])->not->toHaveKey('Customer')
        ->and($data['RiskData']['Name'])->toBe('Test User')
        ->and($data['RiskData']['BillingAddress1'])->toBe('123 Main')
        ->and($data['RiskData']['BillingPostalCode'])->toBe('10001')
        ->and($data['RiskData']['BillingCountryCode'])->toBe('US')
        ->and($data['RiskData']['Email'])->toBe('test@test.com');
});

it('includes StatementDescription when set', function () {
    $card = new CreditCard(
        Number::fromNumber('4012000098765439', cpEncrypter()),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Test User'),
        Cvc::fromCvc('999', cpEncrypter()),
    );

    $request = new AuthorizeRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(5000, new Currency('USD')),
        'instrument' => $card,
        'gateway' => cpCredential(),
        'decrypter' => cpDecrypter(),
        'deviceGuid' => 'device-123',
        'statementDescription' => 'ACME Trip 42',
    ]);

    expect($request->getData()['StatementDescription'])->toBe('ACME Trip 42');
});

it('omits StatementDescription when null or empty', function () {
    $card = new CreditCard(
        Number::fromNumber('4012000098765439', cpEncrypter()),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Test User'),
        Cvc::fromCvc('999', cpEncrypter()),
    );

    $request = new AuthorizeRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(5000, new Currency('USD')),
        'instrument' => $card,
        'gateway' => cpCredential(),
        'decrypter' => cpDecrypter(),
        'deviceGuid' => 'device-123',
        'statementDescription' => '',
    ]);

    expect($request->getData())->not->toHaveKey('StatementDescription');
});

it('includes ThreeDS in Card when threeDS is present', function () {
    $card = new CreditCard(
        Number::fromNumber('4012000098765439', cpEncrypter()),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Test User'),
        Cvc::fromCvc('999', cpEncrypter()),
    );

    $threeDS = new ThreeDSResult(
        ThreeDSStatus::Successful,
        'cavv-auth-value',
        ECICode::MastercardSuccessful,
        'ds-txn-auth',
        'acs-txn-auth',
        ThreeDSVersion::V220,
    );

    $request = new AuthorizeRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(5000, new Currency('USD')),
        'instrument' => $card,
        'gateway' => cpCredential(),
        'decrypter' => cpDecrypter(),
        'deviceGuid' => 'device-123',
        'threeDS' => $threeDS,
    ]);

    $data = $request->getData();

    expect($data['Card']['ThreeDS'])->toBe([
        'Cavv' => 'cavv-auth-value',
        'Version' => '2.2.0',
        'DirectoryServerTransactionID' => 'ds-txn-auth',
        'AcsTransactionId' => 'acs-txn-auth',
        'ECI' => '02',
    ]);
});

it('excludes ThreeDS when threeDS is null', function () {
    $card = new CreditCard(
        Number::fromNumber('4012000098765439', cpEncrypter()),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Test User'),
        Cvc::fromCvc('999', cpEncrypter()),
    );

    $request = new AuthorizeRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(5000, new Currency('USD')),
        'instrument' => $card,
        'gateway' => cpCredential(),
        'decrypter' => cpDecrypter(),
        'deviceGuid' => 'device-123',
    ]);

    $data = $request->getData();

    expect($data['Card'])->not->toHaveKey('ThreeDS');
});

