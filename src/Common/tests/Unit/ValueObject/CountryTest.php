<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ShreddingStubs;
use Techork\PaymentService\Common\ValueObject\Country;

it('constructs from alpha2 code', function () {
    $country = new Country('US');

    expect((string) $country)->toBe('US');
});

it('constructs from alpha3 code', function () {
    $country = new Country('USA');

    expect((string) $country)->toBe('US');
});

it('constructs from numeric code', function () {
    $country = new Country('840');

    expect((string) $country)->toBe('US');
});

it('throws on invalid country code', function () {
    new Country('XX');
})->throws(RuntimeException::class, 'Country is not valid');

it('accepts the shredding-stub code "ZZ" as a sentinel', function () {
    $country = new Country(ShreddingStubs::COUNTRY);

    expect((string) $country)->toBe('ZZ');
});

it('returns alpha3 code', function () {
    $country = new Country('US');

    expect($country->getAlpha3())->toBe('USA');
});

it('serializes to json as alpha2', function () {
    $country = new Country('US');

    expect($country->jsonSerialize())->toBe('US');
});
