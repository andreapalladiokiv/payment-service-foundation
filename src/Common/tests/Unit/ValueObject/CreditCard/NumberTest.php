<?php

declare(strict_types=1);

use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\Contract\EncryptInterface;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;

it('constructs with first6, last4 and brand', function () {
    $number = new Number('424242', '4242', CardBrand::Visa);

    expect($number->first6)->toBe('424242')
        ->and($number->last4)->toBe('4242')
        ->and($number->brand)->toBe(CardBrand::Visa);
});

it('creates from full card number via fromNumber', function () {
    $encrypter = Mockery::mock(EncryptInterface::class);
    $encrypter->shouldReceive('encrypt')
        ->once()
        ->with('4242424242424242')
        ->andReturn('encrypted_data');

    $number = Number::fromNumber('4242424242424242', $encrypter);

    expect($number->first6)->toBe('424242')
        ->and($number->last4)->toBe('4242');
});

it('returns encrypted number via getNumber', function () {
    $encrypter = Mockery::mock(EncryptInterface::class);
    $encrypter->shouldReceive('encrypt')->andReturn('encrypted_data');

    $decrypter = Mockery::mock(DecryptInterface::class);
    $decrypter->shouldReceive('decrypt')
        ->with('encrypted_data')
        ->andReturn('4242424242424242');

    $number = Number::fromNumber('4242424242424242', $encrypter);

    expect($number->getNumber($decrypter))->toBe('4242424242424242');
});

it('returns null from getNumber when no encrypted data', function () {
    $number = new Number('424242', '4242', CardBrand::Visa);

    $decrypter = Mockery::mock(DecryptInterface::class);

    expect($number->getNumber($decrypter))->toBeNull();
});

it('converts to string as first6 + last4', function () {
    $number = new Number('424242', '4242', CardBrand::Visa);

    expect((string) $number)->toBe('4242424242');
});

it('serializes to json', function () {
    $number = new Number('424242', '4242', CardBrand::Visa);

    expect($number->jsonSerialize())->toBe([
        'brand' => 'visa',
        'first6' => '424242',
        'last4' => '4242',
    ]);
});

it('returns safe debug info', function () {
    $number = new Number('424242', '4242', CardBrand::Visa);

    expect($number->__debugInfo())->toBe([
        'first6' => '424242',
        'last4' => '4242',
        'brand' => 'visa',
    ]);
});

it('throws RuntimeException for an unrecognized card number', function () {
    $encrypter = new class implements EncryptInterface
    {
        public function encrypt(string $value): string
        {
            return 'encrypted';
        }
    };

    Number::fromNumber('0000000000000000', $encrypter);
})->throws(RuntimeException::class, 'Unable to detect card brand from number.');

it('detects visa brand from number', function () {
    $encrypter = Mockery::mock(EncryptInterface::class);
    $encrypter->shouldReceive('encrypt')->andReturn('enc');

    $number = Number::fromNumber('4242424242424242', $encrypter);

    expect($number->brand)->toBe(CardBrand::Visa);
});

it('detects mastercard brand from number', function () {
    $encrypter = Mockery::mock(EncryptInterface::class);
    $encrypter->shouldReceive('encrypt')->andReturn('enc');

    $number = Number::fromNumber('5500000000000004', $encrypter);

    expect($number->brand)->toBe(CardBrand::Mastercard);
});

it('detects amex brand from number', function () {
    $encrypter = Mockery::mock(EncryptInterface::class);
    $encrypter->shouldReceive('encrypt')->andReturn('enc');

    $number = Number::fromNumber('370000000000002', $encrypter);

    expect($number->brand)->toBe(CardBrand::Amex);
});
