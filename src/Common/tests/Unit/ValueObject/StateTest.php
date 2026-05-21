<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\State;

// ──────────────────────────────────────────────
//  Constructor — no country (no validation)
// ──────────────────────────────────────────────

it('constructs with state code only and returns it via __toString', function () {
    $state = new State('NY');

    expect((string) $state)->toBe('NY');
});

it('serializes to json as the state code', function () {
    $state = new State('CA');

    expect($state->jsonSerialize())->toBe('CA');
});

// ──────────────────────────────────────────────
//  Constructor — with valid country + state
// ──────────────────────────────────────────────

it('constructs a US state and exposes its full name', function () {
    $state = new State('NY', new Country('US'));

    expect((string) $state)->toBe('NY')
        ->and($state->getName())->toBe('NEW YORK');
});

it('constructs an Australian state and exposes its full name', function () {
    $state = new State('NSW', new Country('AU'));

    expect((string) $state)->toBe('NSW')
        ->and($state->getName())->toBe('NEW SOUTH WALES');
});

it('constructs a Canadian province and exposes its full name', function () {
    $state = new State('ON', new Country('CA'));

    expect((string) $state)->toBe('ON')
        ->and($state->getName())->toBe('ONTARIO');
});

it('constructs a GB region and exposes its full name', function () {
    $state = new State('KEN', new Country('GB'));

    expect((string) $state)->toBe('KEN')
        ->and($state->getName())->toBe('KENT');
});

it('constructs an Indian state and exposes its full name', function () {
    $state = new State('MH', new Country('IN'));

    expect((string) $state)->toBe('MH')
        ->and($state->getName())->toBe('MAHARASHTRA');
});

it('constructs a New Zealand region and exposes its full name', function () {
    $state = new State('AUK', new Country('NZ'));

    expect((string) $state)->toBe('AUK')
        ->and($state->getName())->toBe('AUCKLAND');
});

// ──────────────────────────────────────────────
//  Constructor — invalid country (not in STATES map)
// ──────────────────────────────────────────────

it('throws RuntimeException when country has no states defined', function () {
    new State('IDF', new Country('FR'));
})->throws(RuntimeException::class);

it('throws RuntimeException for another unsupported country', function () {
    new State('BER', new Country('DE'));
})->throws(RuntimeException::class);

// ──────────────────────────────────────────────
//  State::all() without country
// ──────────────────────────────────────────────

it('all() returns a non-empty array of State instances when called without country', function () {
    $states = State::all();

    expect($states)->not->toBeEmpty()
        ->and($states[0])->toBeInstanceOf(State::class);
});

it('all() includes US states when called without country', function () {
    $states = State::all();

    $codes = array_map(fn (State $s) => (string) $s, $states);

    expect($codes)->toContain('NY')
        ->and($codes)->toContain('CA')
        ->and($codes)->toContain('TX');
});

// ──────────────────────────────────────────────
//  all() with unsupported country returns empty
// ──────────────────────────────────────────────

it('all() returns empty array for a country with no states defined', function () {
    expect(State::all(new Country('FR')))->toBe([]);
});

// ──────────────────────────────────────────────
//  all() with supported country
// ──────────────────────────────────────────────

it('all() with a supported country returns State instances for that country', function () {
    $states = State::all(new Country('AU'));

    expect($states)->not->toBeEmpty()
        ->and($states[0])->toBeInstanceOf(State::class);

    $codes = array_map(fn (State $s) => (string) $s, $states);

    expect($codes)->toContain('NSW')
        ->and($codes)->toContain('VIC')
        ->and($codes)->not->toContain('NY');
});

// ──────────────────────────────────────────────
//  getCountry()
// ──────────────────────────────────────────────

it('getCountry returns the country code when constructed with a country', function () {
    $state = new State('NY', new Country('US'));

    expect($state->getCountry())->toBe('US');
});

it('getCountry returns null when constructed without a country', function () {
    $state = new State('NY');

    expect($state->getCountry())->toBeNull();
});
