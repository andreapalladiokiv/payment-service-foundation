<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult;
use Techork\PaymentService\ConnexPay\ConnexPaySchemeChecks;

it('maps AVS letters to (line, postal) tuples', function (string $letter, CheckResult $line, CheckResult $postal) {
    expect(ConnexPaySchemeChecks::avsToLineAndPostal($letter))->toBe([$line, $postal]);
})->with([
    ['Y', CheckResult::Pass, CheckResult::Pass],
    ['X', CheckResult::Pass, CheckResult::Pass],
    ['A', CheckResult::Pass, CheckResult::Fail],
    ['Z', CheckResult::Fail, CheckResult::Pass],
    ['N', CheckResult::Fail, CheckResult::Fail],
    ['U', CheckResult::Unavailable, CheckResult::Unavailable],
    ['G', CheckResult::Unavailable, CheckResult::Unavailable],
    ['E', CheckResult::Unchecked, CheckResult::Unchecked],
    // ConnexPay-specific: '0' means not run
    ['0', CheckResult::Unchecked, CheckResult::Unchecked],
]);

it('maps CVV S to Fail (protocol violation)', function () {
    expect(ConnexPaySchemeChecks::cvvToCheckResult('S'))->toBe(CheckResult::Fail);
});

it('treats null/empty input as Unchecked', function (?string $letter) {
    expect(ConnexPaySchemeChecks::avsToLineAndPostal($letter))
        ->toBe([CheckResult::Unchecked, CheckResult::Unchecked])
        ->and(ConnexPaySchemeChecks::cvvToCheckResult($letter))->toBe(CheckResult::Unchecked);
})->with([null, '']);
