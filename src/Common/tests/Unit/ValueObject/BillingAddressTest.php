<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ShreddingStubs;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\State;

it('constructs with all required fields', function () {
    $address = new BillingAddress(
        line: '123 Main St',
        city: 'New York',
        country: new Country('US'),
        postalCode: '10001',
    );

    expect($address->line)->toBe('123 Main St')
        ->and($address->city)->toBe('New York')
        ->and((string) $address->country)->toBe('US')
        ->and($address->postalCode)->toBe('10001')
        ->and($address->lineExtra)->toBe('')
        ->and($address->state)->toBeNull();
});

it('constructs with all optional fields', function () {
    $address = new BillingAddress(
        line: '123 Main St',
        city: 'New York',
        country: new Country('US'),
        postalCode: '10001',
        lineExtra: 'Apt 4B',
        state: new State('NY'),
    );

    expect($address->line)->toBe('123 Main St')
        ->and($address->lineExtra)->toBe('Apt 4B')
        ->and((string) $address->state)->toBe('NY');
});

/**
 * The four fields this type no longer has, pinned as absent.
 *
 * A name, an email and a phone were properties here, which made an address the de-facto record
 * of who was paying — one copy per card, corrected nowhere. They belong to
 * {@see \Techork\PaymentService\Common\ValueObject\CustomerIdentity}, and the two halves travel
 * together inside a {@see \Techork\PaymentService\Common\ValueObject\Customer}.
 *
 * Asserted rather than left implied, because the failure this prevents is silent: a caller that
 * goes on passing `firstName:` gets a TypeError, but one that *reads* `$address->firstName` on a
 * dynamic path would get null on a class that is not `readonly`-strict about it. This says the
 * property is gone, in the file somebody would look in.
 */
it('carries no part of the payer', function () {
    $address = new BillingAddress('1 St', 'NYC', new Country('US'), '10001');

    expect(array_keys($address->toArray()))
        ->toBe(['line', 'line_extra', 'city', 'country', 'postal_code', 'state'])
        ->and(property_exists($address, 'firstName'))->toBeFalse()
        ->and(property_exists($address, 'lastName'))->toBeFalse()
        ->and(property_exists($address, 'email'))->toBeFalse()
        ->and(property_exists($address, 'phone'))->toBeFalse();
});

it('serializes to array with all fields', function () {
    $address = new BillingAddress(
        line: '123 Main St',
        city: 'New York',
        country: new Country('US'),
        postalCode: '10001',
        lineExtra: 'Apt 4B',
        state: new State('NY'),
    );

    expect($address->toArray())->toBe([
        'line' => '123 Main St',
        'line_extra' => 'Apt 4B',
        'city' => 'New York',
        'country' => 'US',
        'postal_code' => '10001',
        'state' => 'NY',
    ]);
});

it('serializes to array with null optional fields', function () {
    $address = new BillingAddress(
        line: '456 Elm Rd',
        city: 'London',
        country: new Country('GB'),
        postalCode: 'SW1A 1AA',
    );

    expect($address->toArray())->toBe([
        'line' => '456 Elm Rd',
        'line_extra' => '',
        'city' => 'London',
        'country' => 'GB',
        'postal_code' => 'SW1A 1AA',
        'state' => null,
    ]);
});

it('deserializes from array with all fields', function () {
    $address = BillingAddress::fromArray([
        'line' => '123 Main St',
        'line_extra' => 'Apt 4B',
        'city' => 'New York',
        'country' => 'US',
        'postal_code' => '10001',
        'state' => 'NY',
    ]);

    expect($address->line)->toBe('123 Main St')
        ->and($address->lineExtra)->toBe('Apt 4B')
        ->and($address->city)->toBe('New York')
        ->and((string) $address->country)->toBe('US')
        ->and($address->postalCode)->toBe('10001')
        ->and((string) $address->state)->toBe('NY');
});

it('deserializes from array with missing optional fields', function () {
    $address = BillingAddress::fromArray([
        'line' => '456 Elm Rd',
        'city' => 'London',
        'country' => 'GB',
        'postal_code' => 'SW1A 1AA',
    ]);

    expect($address->line)->toBe('456 Elm Rd')
        ->and($address->lineExtra)->toBe('')
        ->and($address->state)->toBeNull();
});

it('deserializes from array with an empty state', function () {
    $address = BillingAddress::fromArray([
        'line' => '456 Elm Rd',
        'city' => 'London',
        'country' => 'GB',
        'postal_code' => 'SW1A 1AA',
        'state' => '',
    ]);

    expect($address->state)->toBeNull();
});

/**
 * A row written before the split carries `first_name`, `last_name`, `email` and `phone`.
 * {@see BillingAddress::fromArray()} reads the keys it knows and ignores the rest, so an
 * unmigrated row still yields the address it always described.
 */
it('reads a row that still carries the payer, keeping only the address', function () {
    $address = BillingAddress::fromArray([
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'line' => '1 Analytical Way',
        'line_extra' => '',
        'city' => 'Juneau',
        'country' => 'US',
        'postal_code' => '99801',
        'state' => 'AK',
        'email' => 'ada@example.com',
        'phone' => '+12025550142',
    ]);

    expect($address->toArray())->toBe([
        'line' => '1 Analytical Way',
        'line_extra' => '',
        'city' => 'Juneau',
        'country' => 'US',
        'postal_code' => '99801',
        'state' => 'AK',
    ]);
});

it('survives toArray/fromArray roundtrip with all fields', function () {
    $original = new BillingAddress(
        line: '789 Oak Ave',
        city: 'Sydney',
        country: new Country('AU'),
        postalCode: '2000',
        lineExtra: 'Suite 10',
        state: new State('NSW'),
    );

    expect(BillingAddress::fromArray($original->toArray()))->toEqual($original);
});

it('survives toArray/fromArray roundtrip with minimal fields', function () {
    $original = new BillingAddress(
        line: '1 Test St',
        city: 'Berlin',
        country: new Country('DE'),
        postalCode: '10115',
    );

    expect(BillingAddress::fromArray($original->toArray()))->toEqual($original);
});

/**
 * `unknown()` loses its four person stubs with the fields, and what is left has to stay a
 * complete address: it is what a {@see \Techork\PaymentService\Common\ValueObject\Customer}
 * carries when nobody recorded where the payer lives, and `ZZ` is ISO 3166's own code for an
 * unknown country rather than a guess that would feed AVS something false.
 */
it('answers an unknown address entirely in stubs', function () {
    expect(BillingAddress::unknown()->toArray())->toBe([
        'line' => ShreddingStubs::ADDRESS_LINE,
        'line_extra' => '',
        'city' => ShreddingStubs::CITY,
        'country' => ShreddingStubs::COUNTRY,
        'postal_code' => ShreddingStubs::POSTAL_CODE,
        'state' => null,
    ]);
});
