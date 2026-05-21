<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CreditCard\Address;
use Techork\PaymentService\Common\ValueObject\State;

it('constructs with required fields', function () {
    $address = new Address(
        city: 'New York',
        country: new Country('US'),
        postalCode: '10001',
        line: '123 Main St',
    );

    expect($address->city)->toBe('New York')
        ->and((string) $address->country)->toBe('US')
        ->and($address->postalCode)->toBe('10001')
        ->and($address->line)->toBe('123 Main St')
        ->and($address->lineExtra)->toBe('')
        ->and($address->state)->toBeNull();
});

it('constructs with all optional fields', function () {
    $address = new Address(
        city: 'New York',
        country: new Country('US'),
        postalCode: '10001',
        line: '123 Main St',
        lineExtra: 'Apt 4B',
        state: new State('NY'),
    );

    expect($address->lineExtra)->toBe('Apt 4B')
        ->and((string) $address->state)->toBe('NY');
});
