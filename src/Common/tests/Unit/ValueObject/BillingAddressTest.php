<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\Email;
use Techork\PaymentService\Common\ValueObject\PhoneNumber;
use Techork\PaymentService\Common\ValueObject\State;

it('constructs with all required fields', function () {
    $address = new BillingAddress(
        firstName: 'Test',
        lastName: 'User',
        line: '123 Main St',
        city: 'New York',
        country: new Country('US'),
        postalCode: '10001',
    );

    expect($address->firstName)->toBe('Test')
        ->and($address->lastName)->toBe('User')
        ->and($address->line)->toBe('123 Main St')
        ->and($address->city)->toBe('New York')
        ->and((string) $address->country)->toBe('US')
        ->and($address->postalCode)->toBe('10001')
        ->and($address->lineExtra)->toBe('')
        ->and($address->state)->toBeNull()
        ->and($address->email)->toBeNull()
        ->and($address->phone)->toBeNull();
});

it('constructs with all optional fields', function () {
    $address = new BillingAddress(
        firstName: 'Test',
        lastName: 'User',
        line: '123 Main St',
        city: 'New York',
        country: new Country('US'),
        postalCode: '10001',
        lineExtra: 'Apt 4B',
        state: new State('NY'),
        email: new Email('john@example.com'),
        phone: new PhoneNumber('+14155552671'),
    );

    expect($address->line)->toBe('123 Main St')
        ->and($address->lineExtra)->toBe('Apt 4B')
        ->and((string) $address->state)->toBe('NY')
        ->and((string) $address->email)->toBe('john@example.com')
        ->and((string) $address->phone)->toBe('+14155552671');
});

it('serializes to array with all fields', function () {
    $address = new BillingAddress(
        firstName: 'John',
        lastName: 'Doe',
        line: '123 Main St',
        city: 'New York',
        country: new Country('US'),
        postalCode: '10001',
        lineExtra: 'Apt 4B',
        state: new State('NY'),
        email: new Email('john@example.com'),
        phone: new PhoneNumber('+14155552671'),
    );

    $array = $address->toArray();

    expect($array)->toBe([
        'first_name' => 'John',
        'last_name' => 'Doe',
        'line' => '123 Main St',
        'line_extra' => 'Apt 4B',
        'city' => 'New York',
        'country' => 'US',
        'postal_code' => '10001',
        'state' => 'NY',
        'email' => 'john@example.com',
        'phone' => '+14155552671',
    ]);
});

it('serializes to array with null optional fields', function () {
    $address = new BillingAddress(
        firstName: 'Jane',
        lastName: 'Smith',
        line: '456 Elm Rd',
        city: 'London',
        country: new Country('GB'),
        postalCode: 'SW1A 1AA',
    );

    $array = $address->toArray();

    expect($array)->toBe([
        'first_name' => 'Jane',
        'last_name' => 'Smith',
        'line' => '456 Elm Rd',
        'line_extra' => '',
        'city' => 'London',
        'country' => 'GB',
        'postal_code' => 'SW1A 1AA',
        'state' => null,
        'email' => null,
        'phone' => null,
    ]);
});

it('deserializes from array with all fields', function () {
    $data = [
        'first_name' => 'John',
        'last_name' => 'Doe',
        'line' => '123 Main St',
        'line_extra' => 'Apt 4B',
        'city' => 'New York',
        'country' => 'US',
        'postal_code' => '10001',
        'state' => 'NY',
        'email' => 'john@example.com',
        'phone' => '+14155552671',
    ];

    $address = BillingAddress::fromArray($data);

    expect($address->firstName)->toBe('John')
        ->and($address->lastName)->toBe('Doe')
        ->and($address->line)->toBe('123 Main St')
        ->and($address->lineExtra)->toBe('Apt 4B')
        ->and($address->city)->toBe('New York')
        ->and((string) $address->country)->toBe('US')
        ->and($address->postalCode)->toBe('10001')
        ->and((string) $address->state)->toBe('NY')
        ->and((string) $address->email)->toBe('john@example.com')
        ->and((string) $address->phone)->toBe('+14155552671');
});

it('deserializes from array with missing optional fields', function () {
    $data = [
        'first_name' => 'Jane',
        'last_name' => 'Smith',
        'line' => '456 Elm Rd',
        'city' => 'London',
        'country' => 'GB',
        'postal_code' => 'SW1A 1AA',
    ];

    $address = BillingAddress::fromArray($data);

    expect($address->line)->toBe('456 Elm Rd')
        ->and($address->lineExtra)->toBe('')
        ->and($address->state)->toBeNull()
        ->and($address->email)->toBeNull()
        ->and($address->phone)->toBeNull();
});

it('deserializes from array with empty state and email', function () {
    $data = [
        'first_name' => 'Jane',
        'last_name' => 'Smith',
        'line' => '456 Elm Rd',
        'city' => 'London',
        'country' => 'GB',
        'postal_code' => 'SW1A 1AA',
        'state' => '',
        'email' => '',
        'phone' => '',
    ];

    $address = BillingAddress::fromArray($data);

    expect($address->state)->toBeNull()
        ->and($address->email)->toBeNull()
        ->and($address->phone)->toBeNull();
});

it('survives toArray/fromArray roundtrip with all fields', function () {
    $original = new BillingAddress(
        firstName: 'Alice',
        lastName: 'Johnson',
        line: '789 Oak Ave',
        city: 'Sydney',
        country: new Country('AU'),
        postalCode: '2000',
        lineExtra: 'Suite 10',
        state: new State('NSW'),
        email: new Email('test@example.com'),
        phone: new PhoneNumber('+61412345678'),
    );

    $restored = BillingAddress::fromArray($original->toArray());

    expect($restored->firstName)->toBe($original->firstName)
        ->and($restored->lastName)->toBe($original->lastName)
        ->and($restored->line)->toBe($original->line)
        ->and($restored->lineExtra)->toBe($original->lineExtra)
        ->and($restored->city)->toBe($original->city)
        ->and((string) $restored->country)->toBe((string) $original->country)
        ->and($restored->postalCode)->toBe($original->postalCode)
        ->and((string) $restored->state)->toBe((string) $original->state)
        ->and((string) $restored->email)->toBe((string) $original->email)
        ->and((string) $restored->phone)->toBe((string) $original->phone);
});

it('survives toArray/fromArray roundtrip with minimal fields', function () {
    $original = new BillingAddress(
        firstName: 'Bob',
        lastName: 'Brown',
        line: '1 Test St',
        city: 'Berlin',
        country: new Country('DE'),
        postalCode: '10115',
    );

    $restored = BillingAddress::fromArray($original->toArray());

    expect($restored->firstName)->toBe($original->firstName)
        ->and($restored->lastName)->toBe($original->lastName)
        ->and($restored->line)->toBe($original->line)
        ->and($restored->city)->toBe($original->city)
        ->and((string) $restored->country)->toBe((string) $original->country)
        ->and($restored->postalCode)->toBe($original->postalCode)
        ->and($restored->lineExtra)->toBe('')
        ->and($restored->state)->toBeNull()
        ->and($restored->email)->toBeNull()
        ->and($restored->phone)->toBeNull();
});
