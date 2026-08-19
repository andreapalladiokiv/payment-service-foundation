<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\Challenge\RedirectChallenge;
use Techork\PaymentService\Common\ValueObject\Challenge\SdkChallenge;
use Techork\PaymentService\Common\ValueObject\Challenge\ThreeDSChallenge;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSVersion;
use Techork\PaymentService\Domain\PaymentIntent\ChallengeArraySerializer;

/**
 * A challenge rides `PaymentIntentRequiresAction` into an append-only stream, so one that
 * cannot be read back is a payment held against a step nobody can reconstruct. Each shape
 * has to survive its own round-trip, and a shape the serializer has not been taught fails
 * loudly on the way back in rather than quietly on the way out.
 */
it('round-trips every shape a challenge can take', function (callable $make) {
    $challenge = $make();

    $restored = ChallengeArraySerializer::fromArray(ChallengeArraySerializer::toArray($challenge));

    expect($restored)->toEqual($challenge);
})->with([
    'three_ds' => [fn () => new ThreeDSChallenge('auth-1', 'https://acs.example/challenge', 'creq-payload', ThreeDSVersion::V220)],
    'three_ds without a payload' => [fn () => new ThreeDSChallenge('auth-2', 'https://acs.example/challenge')],
    'redirect' => [fn () => new RedirectChallenge('txn-1', 'https://hosted.example/pay', ['a' => 'b'])],
    'sdk' => [fn () => new SdkChallenge('cb533804-6094-4944-8ac4-235c1bbf2c79', 'pi_3U5jL2FhXDZuLIpU1J5wTVs0')],
]);

/**
 * The SDK shape exists partly to keep a credential out of the stream, so what it writes is
 * worth pinning: a handle and a payment reference, and nothing that could confirm a payment.
 */
it('writes no secret into the stream for the SDK-conducted shape', function () {
    $payload = ChallengeArraySerializer::toArray(new SdkChallenge('auth-1', 'pi_1'));

    expect($payload['type'])->toBe('sdk')
        ->and(array_keys($payload['sdk']))->toBe(['authentication_id', 'payment_reference']);
});

/**
 * Rows written before the 3DS keys were renamed still exist, and reading falls back to the
 * old names for them. Writing does not, so the ambiguity does not spread.
 */
it('still reads a 3DS challenge written under the old key names', function () {
    $restored = ChallengeArraySerializer::fromArray([
        'type' => 'three_ds',
        'three_ds' => ['transaction_id' => 'auth-old', 'acs_url' => 'https://acs.example/old', 'creq' => 'creq-old'],
    ]);

    expect($restored)->toBeInstanceOf(ThreeDSChallenge::class)
        ->and($restored->authenticationId)->toBe('auth-old')
        ->and($restored->url)->toBe('https://acs.example/old')
        ->and($restored->payload)->toBe('creq-old');
});
