<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\Contract\EncryptInterface;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\Email;
use Techork\PaymentService\Common\ValueObject\State;
use Techork\PaymentService\ConnexPay\AuthorizeRequest;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

function billingFormatCredential(): GatewayCredential
{
    return new readonly class implements GatewayCredential {
        public function getId(): GatewayId { return GatewayId::generate(); }
        public function getGatewayName(): string { return 'ConnexPay'; }
        public function getCredentials(): array { return []; }
    };
}

function billingFormatEncrypter(): EncryptInterface
{
    return new class implements EncryptInterface { public function encrypt(string $d): string { return $d; } };
}

function billingFormatDecrypter(): DecryptInterface
{
    return new class implements DecryptInterface { public function decrypt(string $d): string { return $d; } };
}

it('forwards full BillingAddress to top-level RiskData (no N/A hardcode)', function () {
    $card = new CreditCard(
        Number::fromNumber('4012000098765439', billingFormatEncrypter()),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Jane Smith'),
        Cvc::fromCvc('999', billingFormatEncrypter()),
    );

    $billing = new BillingAddress(
        firstName: 'Jane',
        lastName: 'Smith',
        line: '456 Oak Ave',
        city: 'Tempe',
        country: new Country('US'),
        postalCode: '85284',
        state: new State('AZ'),
        email: new Email('jane@test.com'),
    );

    $request = new AuthorizeRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(450, new Currency('USD')),
        'instrument' => $card,
        'gateway' => billingFormatCredential(),
        'decrypter' => billingFormatDecrypter(),
        'billingAddress' => $billing,
        'deviceGuid' => 'device-1',
    ]);

    $data = $request->getData();
    $risk = $data['RiskData'];

    expect($data['Card'])->not->toHaveKey('Customer')
        ->and($risk['Name'])->toBe('Jane Smith')
        ->and($risk['Email'])->toBe('jane@test.com')
        ->and($risk['BillingAddress1'])->toBe('456 Oak Ave')
        ->and($risk['BillingState'])->toBe('AZ')
        ->and($risk['BillingCountryCode'])->toBe('US')
        ->and($risk['BillingPostalCode'])->toBe('85284');
});

it('keeps all RiskData keys present with nulls when optional fields are missing', function () {
    $card = new CreditCard(
        Number::fromNumber('4012000098765439', billingFormatEncrypter()),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('John Doe'),
        new Cvc,
    );

    $billing = new BillingAddress(
        firstName: 'John',
        lastName: 'Doe',
        line: '1 St',
        city: 'NYC',
        country: new Country('US'),
        postalCode: '10001',
    );

    $request = new AuthorizeRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(100, new Currency('USD')),
        'instrument' => $card,
        'gateway' => billingFormatCredential(),
        'decrypter' => billingFormatDecrypter(),
        'billingAddress' => $billing,
        'deviceGuid' => 'device-1',
    ]);

    $risk = $request->getData()['RiskData'];

    expect($risk)->toBe([
        'Name' => 'John Doe',
        'BillingPhoneNumber' => null,
        'BillingState' => null,
        'BillingCountryCode' => 'US',
        'Email' => null,
        'BillingAddress1' => '1 St',
        'BillingAddress2' => '',
        'BillingPostalCode' => '10001',
    ]);
});
