<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\PaymentInstrumentFactory;

it('creates CreditCard from card payload', function () {
    $payload = [
        'type' => 'card',
        'first6' => '424242',
        'last4' => '4242',
        'brand' => 'visa',
        'expiration' => '1230',
        'holder' => 'Test',
    ];

    $instrument = PaymentInstrumentFactory::fromPayload($payload);

    expect($instrument)->toBeInstanceOf(CreditCard::class)
        ->and($instrument->number->brand)->toBe(CardBrand::Visa);
});

it('creates Cash from cash payload', function () {
    $instrument = PaymentInstrumentFactory::fromPayload(['type' => 'cash']);

    expect($instrument)->toBeInstanceOf(Cash::class);
});

it('throws on unknown instrument type', function () {
    PaymentInstrumentFactory::fromPayload(['type' => 'bitcoin']);
})->throws(InvalidArgumentException::class, 'Unknown instrument type: bitcoin');
