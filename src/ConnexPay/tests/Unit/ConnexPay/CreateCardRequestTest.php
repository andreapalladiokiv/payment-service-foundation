<?php

declare(strict_types=1);

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
use Techork\PaymentService\Common\ValueObject\State;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Common\ValueObject\TokenId;
use Techork\PaymentService\ConnexPay\CreateCardRequest;

it('builds verify data for credit card', function () {
    $enc = new class implements EncryptInterface { public function encrypt(string $d): string { return $d; } };
    $dec = new class implements DecryptInterface { public function decrypt(string $d): string { return $d; } };

    $card = new CreditCard(
        Number::fromNumber('4012000098765439', $enc),
        Expiration::fromMonthAndYear(6, 2028),
        new Holder('Jane Doe'),
        Cvc::fromCvc('999', $enc),
    );

    $request = new CreateCardRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'instrument' => $card,
        'decrypter' => $dec,
        'deviceGuid' => 'device-abc',
    ]);

    $data = $request->getData();

    expect($data['DeviceGuid'])->toBe('device-abc')
        ->and($data['Card']['CardNumber'])->toBe('4012000098765439')
        ->and($data['Card']['ExpirationDate'])->toBe('2806')
        ->and($data['Card']['Cvv2'])->toBe('999')
        ->and($data['Card']['CardHolderName'])->toBe('Jane Doe');
});

it('omits CVV when empty and forwards empty CardHolderName', function () {
    $enc = new class implements EncryptInterface { public function encrypt(string $d): string { return $d; } };
    $dec = new class implements DecryptInterface { public function decrypt(string $d): string { return $d; } };

    $card = new CreditCard(
        Number::fromNumber('4012000098765439', $enc),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder(''),
        new Cvc,
    );

    $request = new CreateCardRequest(new OmnipayClient, new HttpRequest);
    $request->initialize(['instrument' => $card, 'decrypter' => $dec, 'deviceGuid' => 'd']);

    $data = $request->getData();

    expect($data['Card'])->not->toHaveKey('Cvv2')
        ->and($data['Card']['CardHolderName'])->toBe('');
});

it('includes Customer block when billingAddress is provided', function () {
    $enc = new class implements EncryptInterface { public function encrypt(string $d): string { return $d; } };
    $dec = new class implements DecryptInterface { public function decrypt(string $d): string { return $d; } };

    $card = new CreditCard(
        Number::fromNumber('4012000098765439', $enc),
        Expiration::fromMonthAndYear(6, 2028),
        new Holder('Jane Doe'),
        Cvc::fromCvc('999', $enc),
    );

    $billing = new BillingAddress(
        firstName: 'Jane',
        lastName: 'Doe',
        line: '456 Oak Ave',
        city: 'Tempe',
        country: new Country('US'),
        postalCode: '85284',
        state: new State('AZ'),
        email: new Email('jane@test.com'),
    );

    $request = new CreateCardRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'instrument' => $card,
        'decrypter' => $dec,
        'deviceGuid' => 'device-abc',
        'billingAddress' => $billing,
    ]);

    expect($request->getData()['Card']['Customer'])->toBe([
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
    $enc = new class implements EncryptInterface { public function encrypt(string $d): string { return $d; } };
    $dec = new class implements DecryptInterface { public function decrypt(string $d): string { return $d; } };

    $card = new CreditCard(
        Number::fromNumber('4012000098765439', $enc),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Jane Doe'),
        Cvc::fromCvc('999', $enc),
    );

    $request = new CreateCardRequest(new OmnipayClient, new HttpRequest);
    $request->initialize(['instrument' => $card, 'decrypter' => $dec, 'deviceGuid' => 'd']);

    $data = $request->getData();

    expect($data['Card'])->not->toHaveKey('Customer');
});

it('throws on token instrument', function () {
    $token = new Token(
        TokenId::generate(),
        new CreditCard(new Number('401200', '5439', CardBrand::Visa), Expiration::fromMonthAndYear(12, 2030), new Holder('T'), new Cvc),
        ExpiresAt::fromDateTime(new DateTimeImmutable('+1 hour')),
    );

    $dec = new class implements DecryptInterface { public function decrypt(string $d): string { return $d; } };

    $request = new CreateCardRequest(new OmnipayClient, new HttpRequest);
    $request->initialize(['instrument' => $token, 'decrypter' => $dec]);

    $request->getData();
})->throws(RuntimeException::class, 'Token does not support tokenization');

it('throws on cash instrument', function () {
    $dec = new class implements DecryptInterface { public function decrypt(string $d): string { return $d; } };

    $request = new CreateCardRequest(new OmnipayClient, new HttpRequest);
    $request->initialize(['instrument' => new Cash, 'decrypter' => $dec]);

    $request->getData();
})->throws(RuntimeException::class, 'ConnexPay does not support cash');
