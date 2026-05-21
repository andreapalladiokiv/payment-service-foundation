<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult;

it('exposes four normalized states with expected string values', function () {
    expect(CheckResult::Pass->value)->toBe('pass')
        ->and(CheckResult::Fail->value)->toBe('fail')
        ->and(CheckResult::Unavailable->value)->toBe('unavailable')
        ->and(CheckResult::Unchecked->value)->toBe('unchecked')
        ->and(CheckResult::cases())->toHaveCount(4);
});

it('hydrates from string value', function (string $value, CheckResult $expected) {
    expect(CheckResult::from($value))->toBe($expected);
})->with([
    ['pass', CheckResult::Pass],
    ['fail', CheckResult::Fail],
    ['unavailable', CheckResult::Unavailable],
    ['unchecked', CheckResult::Unchecked],
]);
