<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Address;
use Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\State;

// ──────────────────────────────────────────────
//  fromArray
// ──────────────────────────────────────────────

it('creates CreditCard from array without address', function () {
    $futureDate = new DateTimeImmutable('+2 years')->format('my');

    $card = CreditCard::fromArray([
        'first6' => '411111',
        'last4' => '1111',
        'brand' => 'visa',
        'expiration' => $futureDate,
        'holder' => 'John Doe',
    ]);

    expect($card->number->first6)->toBe('411111')
        ->and($card->number->last4)->toBe('1111')
        ->and($card->number->brand)->toBe(CardBrand::Visa)
        ->and((string) $card->holder)->toBe('John Doe')
        ->and($card->address)->toBeNull();
});

it('creates CreditCard from array with address', function () {
    $futureDate = new DateTimeImmutable('+2 years')->format('my');

    $card = CreditCard::fromArray([
        'first6' => '550000',
        'last4' => '0004',
        'brand' => 'mastercard',
        'expiration' => $futureDate,
        'holder' => 'Jane Doe',
        'address' => [
            'city' => 'New York',
            'country' => new Country('US'),
            'postalCode' => '10001',
            'line' => '123 Main St',
            'lineExtra' => 'Apt 4B',
            'state' => new State('NY'),
        ],
    ]);

    expect($card->address)->toBeInstanceOf(Address::class)
        ->and($card->address->city)->toBe('New York')
        ->and($card->address->line)->toBe('123 Main St')
        ->and($card->address->lineExtra)->toBe('Apt 4B')
        ->and((string) $card->address->country)->toBe('US')
        ->and($card->address->postalCode)->toBe('10001')
        ->and((string) $card->address->state)->toBe('NY');
});

// ──────────────────────────────────────────────
//  isValid / expired
// ──────────────────────────────────────────────

it('reports card as valid when not expired', function () {
    $card = new CreditCard(
        new Number('411111', '1111', CardBrand::Visa),
        Expiration::fromMonthAndYear(12, 30),
        new Holder('Test'),
        new Cvc,
    );

    expect($card->isValid())->toBeTrue()
        ->and($card->expired())->toBeFalse();
});

it('reports card as invalid when expired', function () {
    $card = new CreditCard(
        new Number('411111', '1111', CardBrand::Visa),
        Expiration::fromMonthAndYear(1, 20),
        new Holder('Test'),
        new Cvc,
    );

    expect($card->isValid())->toBeFalse()
        ->and($card->expired())->toBeTrue();
});

// ──────────────────────────────────────────────
//  TYPE constant
// ──────────────────────────────────────────────

it('has TYPE constant set to card', function () {
    expect(CreditCard::type())->toBe('card');
});

// ──────────────────────────────────────────────
//  Verification checks (AVS / CVC)
// ──────────────────────────────────────────────

it('defaults all three verification checks to Unchecked', function () {
    $card = new CreditCard(
        new Number('411111', '1111', CardBrand::Visa),
        Expiration::fromMonthAndYear(12, 30),
        new Holder('Test'),
        new Cvc,
    );

    expect($card->addressLineCheck)->toBe(CheckResult::Unchecked)
        ->and($card->postalCodeCheck)->toBe(CheckResult::Unchecked)
        ->and($card->cvcCheck)->toBe(CheckResult::Unchecked);
});

it('round-trips checks through toPayload / fromPayload', function () {
    $original = new CreditCard(
        new Number('411111', '1111', CardBrand::Visa),
        Expiration::fromMonthAndYear(12, 30),
        new Holder('Test'),
        new Cvc,
        addressLineCheck: CheckResult::Pass,
        postalCodeCheck: CheckResult::Fail,
        cvcCheck: CheckResult::Unavailable,
    );

    $rebuilt = CreditCard::fromPayload($original->toPayload());

    expect($rebuilt->addressLineCheck)->toBe(CheckResult::Pass)
        ->and($rebuilt->postalCodeCheck)->toBe(CheckResult::Fail)
        ->and($rebuilt->cvcCheck)->toBe(CheckResult::Unavailable);
});

it('reads checks from snake_case array keys via fromArray', function () {
    $card = CreditCard::fromArray([
        'first6' => '411111',
        'last4' => '1111',
        'brand' => 'visa',
        'expiration' => new DateTimeImmutable('+2 years')->format('my'),
        'holder' => 'Test',
        'address_line_check' => 'pass',
        'postal_code_check' => 'fail',
        'cvc_check' => 'pass',
    ]);

    expect($card->addressLineCheck)->toBe(CheckResult::Pass)
        ->and($card->postalCodeCheck)->toBe(CheckResult::Fail)
        ->and($card->cvcCheck)->toBe(CheckResult::Pass);
});

it('treats missing check keys as Unchecked (backward compat with pre-checks payloads)', function () {
    $card = CreditCard::fromArray([
        'first6' => '411111',
        'last4' => '1111',
        'brand' => 'visa',
        'expiration' => new DateTimeImmutable('+2 years')->format('my'),
        'holder' => 'Test',
    ]);

    expect($card->addressLineCheck)->toBe(CheckResult::Unchecked)
        ->and($card->postalCodeCheck)->toBe(CheckResult::Unchecked)
        ->and($card->cvcCheck)->toBe(CheckResult::Unchecked);
});
