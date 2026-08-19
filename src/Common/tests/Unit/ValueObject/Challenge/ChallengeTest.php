<?php

declare(strict_types=1);

use Techork\PaymentService\Common\Contract\ChallengeVisitor;
use Techork\PaymentService\Common\ValueObject\Challenge\RedirectChallenge;
use Techork\PaymentService\Common\ValueObject\Challenge\SdkChallenge;
use Techork\PaymentService\Common\ValueObject\Challenge\ThreeDSChallenge;

/**
 * @implements ChallengeVisitor<string>
 */
function challengeTagVisitor(): ChallengeVisitor
{
    return new class implements ChallengeVisitor
    {
        public function visitSdk(SdkChallenge $challenge): string
        {
            return 'sdk:'.$challenge->authenticationId;
        }

        public function visitThreeDS(ThreeDSChallenge $challenge): string
        {
            return 'three_ds:'.$challenge->authenticationId;
        }

        public function visitRedirect(RedirectChallenge $challenge): string
        {
            return 'redirect:'.$challenge->transactionId;
        }
    };
}

it('dispatches ThreeDSChallenge to visitThreeDS', function () {
    $challenge = new ThreeDSChallenge(
        authenticationId: 'txn-1',
        url: 'https://acs.example.com',
        payload: 'base64data',
    );

    expect($challenge->accept(challengeTagVisitor()))->toBe('three_ds:txn-1');
});

it('dispatches RedirectChallenge to visitRedirect', function () {
    $challenge = new RedirectChallenge(
        transactionId: 'pay-99',
        url: 'https://paynet.example/checkout',
        formFields: ['operation' => 'pay-99', 'Signature' => 'sig'],
    );

    expect($challenge->accept(challengeTagVisitor()))->toBe('redirect:pay-99');
});

it('allows a step with no payload, which is a plain redirect', function () {
    $challenge = new ThreeDSChallenge(
        authenticationId: 'pi_stripe_1',
        url: 'https://hooks.stripe.com/3d_secure_2/...',
    );

    expect($challenge->payload)->toBeNull()
        ->and($challenge->url)->toBe('https://hooks.stripe.com/3d_secure_2/...');
});

it('exposes transactionId via interface method', function () {
    $three = new ThreeDSChallenge('txn-a', 'https://acs.test/step');
    $redirect = new RedirectChallenge(transactionId: 'pay-b', url: 'https://x', formFields: []);

    expect($three->transactionId())->toBe('txn-a')
        ->and($redirect->transactionId())->toBe('pay-b');
});

/**
 * The shape with no address: a provider's SDK conducts the authentication in the payer's
 * browser and needs only a handle. It carries no client secret — the challenge is recorded
 * on `PaymentIntentRequiresAction` and projected, and a secret that can confirm the payment
 * would be a credential written into an append-only log. A field the serializer drops
 * instead is the trap {@see \Techork\PaymentService\Common\ValueObject\CreditCard\Cvc} is
 * already in: it comes back null and the caller finds out late.
 */
it('carries a handle rather than an address, and no secret', function () {
    $challenge = new SdkChallenge('cb533804-6094-4944-8ac4-235c1bbf2c79', 'pi_3U5jL2FhXDZuLIpU1J5wTVs0');

    expect($challenge->transactionId())->toBe('cb533804-6094-4944-8ac4-235c1bbf2c79')
        ->and($challenge->paymentReference)->toBe('pi_3U5jL2FhXDZuLIpU1J5wTVs0');

    $properties = array_map(
        static fn (ReflectionProperty $p): string => $p->getName(),
        new ReflectionClass(SdkChallenge::class)->getProperties(),
    );

    expect($properties)->toBe(['authenticationId', 'paymentReference']);
});
