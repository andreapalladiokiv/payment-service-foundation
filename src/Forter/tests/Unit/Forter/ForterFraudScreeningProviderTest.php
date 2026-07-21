<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\Risk\FraudDecision;
use Techork\PaymentService\Forter\ForterFraudScreeningProvider;

it('maps Forter actions onto fraud decisions', function (string $action, FraudDecision $expected) {
    $provider = new ForterFraudScreeningProvider(fakeForterClient(['action' => $action]));

    expect($provider->screen(makeForterScreeningRequest())->decision)->toBe($expected);
})->with([
    ['approve', FraudDecision::Approve],
    ['decline', FraudDecision::Decline],
    ['not reviewed', FraudDecision::NotReviewed],
]);

it('carries the reason code and provider transaction reference', function () {
    $provider = new ForterFraudScreeningProvider(fakeForterClient([
        'action' => 'decline',
        'reasonCode' => 'HIGH_RISK',
        'transaction' => 'forter-txn-9',
    ]));

    $verdict = $provider->screen(makeForterScreeningRequest());

    expect($verdict->isDeclined())->toBeTrue()
        ->and($verdict->reasonCode)->toBe('HIGH_RISK')
        ->and($verdict->reference)->toBe('forter-txn-9');
});

it('falls back to the request reference when Forter returns none', function () {
    $provider = new ForterFraudScreeningProvider(fakeForterClient(['action' => 'approve']));

    expect($provider->screen(makeForterScreeningRequest())->reference)->toBe('fraud-ref-1');
});

it('returns an errored verdict when the transport fails (fail-soft)', function () {
    $provider = new ForterFraudScreeningProvider(fakeForterClient(throws: new RuntimeException('timeout')));

    $verdict = $provider->screen(makeForterScreeningRequest());

    expect($verdict->decision)->toBe(FraudDecision::Errored)
        ->and($verdict->isInconclusive())->toBeTrue();
});

it('returns an errored verdict when the response has no recognizable action', function () {
    $provider = new ForterFraudScreeningProvider(fakeForterClient(['message' => 'weird']));

    expect($provider->screen(makeForterScreeningRequest())->decision)->toBe(FraudDecision::Errored);
});
