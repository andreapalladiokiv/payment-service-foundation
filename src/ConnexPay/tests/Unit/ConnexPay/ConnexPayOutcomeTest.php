<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult;

use Techork\PaymentService\Gateway\Contract\AuthorizationResult;
use Techork\PaymentService\Gateway\Contract\GatewayResult;

/**
 * The shared reading of a ConnexPay sales-API body — what `ConnexPayResponse` and its six empty
 * subclasses used to be, now a concern the operations mix in.
 *
 * Exercised through {@see \Techork\PaymentService\ConnexPay\Authorize} and
 * {@see \Techork\PaymentService\ConnexPay\Capture} rather than in isolation, because the whole
 * point of the change is that there is no free-standing response object to hand a payload to.
 * The two operations cover the two shapes the trait produces: an authorization with challenge and
 * card checks, and a bare outcome.
 *
 * @param  array<string, mixed>  $body
 */
function cpAuthorizationFor(array $body): AuthorizationResult
{
    $client = cpHttpClient();
    $client->shouldReceive('post')->once()->andReturn($body);

    return cpAuthorize(['instrument' => cpCard()], ['client' => $client])->authorize();
}

/**
 * @param  array<string, mixed>  $body
 */
function cpOutcomeFor(array $body): GatewayResult
{
    $client = cpHttpClient();
    $client->shouldReceive('post')->once()->andReturn($body);

    return cpCapture(client: $client)->capture();
}

it('surfaces the incoming transaction code as transaction metadata', function () {
    $result = cpOutcomeFor([
        'wasProcessed' => true,
        'guid' => 'sale-guid',
        'connexPayTransaction' => ['incomingTransCode' => 'ICT-001'],
    ]);

    expect($result->metadata)->toBe(['incoming_transaction_code' => 'ICT-001']);
});

it('surfaces the incoming transaction code from PascalCase payloads', function () {
    $result = cpOutcomeFor([
        'wasProcessed' => true,
        'guid' => 'sale-guid',
        'ConnexPayTransaction' => ['IncomingTransCode' => 'ICT-002'],
    ]);

    expect($result->metadata)->toBe(['incoming_transaction_code' => 'ICT-002']);
});

it('returns empty metadata when the incoming transaction code is absent or empty', function () {
    expect(cpOutcomeFor(['wasProcessed' => true, 'guid' => 'g'])->metadata)->toBe([])
        ->and(cpOutcomeFor([
            'wasProcessed' => true,
            'guid' => 'g',
            'connexPayTransaction' => ['incomingTransCode' => ''],
        ])->metadata)->toBe([]);
});

it('returns null for all checks when codes absent', function () {
    $result = cpAuthorizationFor(['wasProcessed' => true, 'guid' => 'abc']);

    expect($result->addressLineCheck)->toBeNull()
        ->and($result->postalCodeCheck)->toBeNull()
        ->and($result->cvcCheck)->toBeNull();
});

it('decomposes Y AVS into (Pass, Pass)', function () {
    $result = cpAuthorizationFor(['wasProcessed' => true, 'guid' => 'abc', 'addressVerificationCode' => 'Y']);

    expect($result->addressLineCheck)->toBe(CheckResult::Pass)
        ->and($result->postalCodeCheck)->toBe(CheckResult::Pass);
});

it('decomposes A AVS into (Pass, Fail)', function () {
    $result = cpAuthorizationFor(['wasProcessed' => true, 'guid' => 'abc', 'addressVerificationCode' => 'A']);

    expect($result->addressLineCheck)->toBe(CheckResult::Pass)
        ->and($result->postalCodeCheck)->toBe(CheckResult::Fail);
});

it('treats 0 AVS as Unchecked (not run)', function () {
    $result = cpAuthorizationFor(['wasProcessed' => true, 'guid' => 'abc', 'addressVerificationCode' => '0']);

    expect($result->addressLineCheck)->toBe(CheckResult::Unchecked)
        ->and($result->postalCodeCheck)->toBe(CheckResult::Unchecked);
});

it('maps CVV M to Pass and N to Fail', function () {
    expect(cpAuthorizationFor(['wasProcessed' => true, 'guid' => 'a', 'cvvVerificationCode' => 'M'])->cvcCheck)
        ->toBe(CheckResult::Pass)
        ->and(cpAuthorizationFor(['wasProcessed' => true, 'guid' => 'a', 'cvvVerificationCode' => 'N'])->cvcCheck)
        ->toBe(CheckResult::Fail);
});

it('accepts PascalCase keys (defensive)', function () {
    $result = cpAuthorizationFor([
        'wasProcessed' => true,
        'guid' => 'abc',
        'AddressVerificationCode' => 'Y',
        'CvvVerificationCode' => 'M',
    ]);

    expect($result->addressLineCheck)->toBe(CheckResult::Pass)
        ->and($result->cvcCheck)->toBe(CheckResult::Pass);
});

it('reads the message from processorResponseMessage, then status', function () {
    expect(cpOutcomeFor(['wasProcessed' => false, 'processorResponseMessage' => 'Declined'])->message)
        ->toBe('Declined')
        ->and(cpOutcomeFor(['wasProcessed' => false, 'status' => 'Transaction - Declined'])->message)
        ->toBe('Transaction - Declined')
        ->and(cpOutcomeFor(['wasProcessed' => false])->message)
        ->toBe('Gateway returned an unsuccessful response.');
});

/**
 * A success naming no guid is unreachable afterwards — nothing could capture, cancel or refund
 * it — so it is reported as a failure rather than recorded as a payment.
 */
it('refuses a success that names no transaction', function () {
    $result = cpOutcomeFor(['wasProcessed' => true, 'guid' => '']);

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe(GatewayResult::UNNAMED_SUCCESS);
});

/*
 * One test is gone and cannot come back: "it implements CardChecksProvider". The AVS / CVC
 * signals are fields on {@see AuthorizationResult} now, so there is no response object left to
 * carry the interface — the assertions above are what it was really guarding.
 */
