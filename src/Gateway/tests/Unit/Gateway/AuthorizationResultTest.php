<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\Challenge\ThreeDSChallenge;
use Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult;
use Techork\PaymentService\Gateway\Contract\AuthorizationResult;
use Techork\PaymentService\Gateway\Contract\GatewayResult;

it('extends GatewayResult so callers can treat it as the lean outcome', function () {
    expect(AuthorizationResult::succeeded('ref-1'))->toBeInstanceOf(GatewayResult::class);
});

it('creates a succeeded authorization with no challenge and no checks', function () {
    $result = AuthorizationResult::succeeded('ref-1');

    expect($result->success)->toBeTrue()
        ->and($result->reference)->toBe('ref-1')
        ->and($result->challenge)->toBeNull()
        ->and($result->isRequiresAction())->toBeFalse()
        ->and($result->hasChecks())->toBeFalse();
});

it('creates a failed authorization', function () {
    $result = AuthorizationResult::failed('Card declined');

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('Card declined')
        ->and($result->isRequiresAction())->toBeFalse();
});

it('creates a requires-action authorization that carries the challenge', function () {
    $challenge = new ThreeDSChallenge(
        authenticationId: 'gw-txn-123',
        url: 'https://acs.example.com/challenge',
        payload: 'base64-creq',
    );

    $result = AuthorizationResult::requiresAction('gw-txn-123', $challenge);

    expect($result->success)->toBeFalse()
        ->and($result->reference)->toBe('gw-txn-123')
        ->and($result->challenge)->toBe($challenge)
        ->and($result->isRequiresAction())->toBeTrue();
});

it('attaches checks via withChecks preserving challenge and outcome fields', function () {
    $challenge = new ThreeDSChallenge(
        authenticationId: 'gw-txn-7',
        url: 'https://acs.example.com/challenge',
        payload: 'creq',
    );

    $result = AuthorizationResult::requiresAction('gw-txn-7', $challenge)
        ->withChecks(CheckResult::Pass, CheckResult::Fail, CheckResult::Unavailable);

    expect($result->reference)->toBe('gw-txn-7')
        ->and($result->challenge)->toBe($challenge)
        ->and($result->addressLineCheck)->toBe(CheckResult::Pass)
        ->and($result->postalCodeCheck)->toBe(CheckResult::Fail)
        ->and($result->cvcCheck)->toBe(CheckResult::Unavailable)
        ->and($result->hasChecks())->toBeTrue();
});

it('reports hasChecks=true when at least one check is non-null', function () {
    $result = AuthorizationResult::succeeded('ref-1')
        ->withChecks(null, null, CheckResult::Pass);

    expect($result->hasChecks())->toBeTrue()
        ->and($result->cvcCheck)->toBe(CheckResult::Pass);
});

it('treats Unchecked as a real signal (distinct from null = no signal)', function () {
    $result = AuthorizationResult::succeeded('ref-1')
        ->withChecks(CheckResult::Unchecked, null, null);

    expect($result->hasChecks())->toBeTrue()
        ->and($result->addressLineCheck)->toBe(CheckResult::Unchecked);
});
