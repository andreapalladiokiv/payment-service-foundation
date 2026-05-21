<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Gateway\Contract\RegistrationResult;

it('extends GatewayResult', function () {
    expect(RegistrationResult::succeeded('tok_1'))->toBeInstanceOf(GatewayResult::class);
});

it('creates a succeeded registration with no customer and no checks', function () {
    $result = RegistrationResult::succeeded('tok_1');

    expect($result->success)->toBeTrue()
        ->and($result->reference)->toBe('tok_1')
        ->and($result->customerReference)->toBeNull()
        ->and($result->hasChecks())->toBeFalse();
});

it('attaches customer reference via withCustomerReference', function () {
    $result = RegistrationResult::succeeded('tok_1')->withCustomerReference('cus_42');

    expect($result->customerReference)->toBe('cus_42');
});

it('creates a failed registration', function () {
    $result = RegistrationResult::failed('Card declined');

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('Card declined');
});

it('attaches checks via withChecks preserving customer reference', function () {
    $result = RegistrationResult::succeeded('tok_2')
        ->withCustomerReference('cus_9')
        ->withChecks(CheckResult::Pass, CheckResult::Fail, CheckResult::Pass);

    expect($result->customerReference)->toBe('cus_9')
        ->and($result->addressLineCheck)->toBe(CheckResult::Pass)
        ->and($result->postalCodeCheck)->toBe(CheckResult::Fail)
        ->and($result->cvcCheck)->toBe(CheckResult::Pass)
        ->and($result->hasChecks())->toBeTrue();
});
