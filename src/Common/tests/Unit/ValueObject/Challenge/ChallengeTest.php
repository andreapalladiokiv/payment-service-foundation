<?php

declare(strict_types=1);

use Techork\PaymentService\Common\Contract\ChallengeVisitor;
use Techork\PaymentService\Common\ValueObject\Challenge\RedirectChallenge;
use Techork\PaymentService\Common\ValueObject\Challenge\ThreeDSChallenge;

/**
 * @implements ChallengeVisitor<string>
 */
function challengeTagVisitor(): ChallengeVisitor
{
    return new class implements ChallengeVisitor
    {
        public function visitThreeDS(ThreeDSChallenge $challenge): string
        {
            return 'three_ds:'.$challenge->transactionId;
        }

        public function visitRedirect(RedirectChallenge $challenge): string
        {
            return 'redirect:'.$challenge->transactionId;
        }
    };
}

it('dispatches ThreeDSChallenge to visitThreeDS', function () {
    $challenge = new ThreeDSChallenge(
        transactionId: 'txn-1',
        acsUrl: 'https://acs.example.com',
        creq: 'base64data',
        clientSecret: 'secret',
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

it('allows Stripe-style ThreeDSChallenge without creq', function () {
    $challenge = new ThreeDSChallenge(
        transactionId: 'pi_stripe_1',
        acsUrl: 'https://hooks.stripe.com/3d_secure_2/...',
        clientSecret: 'pi_stripe_1_secret_abc',
    );

    expect($challenge->creq)->toBeNull()
        ->and($challenge->clientSecret)->toBe('pi_stripe_1_secret_abc');
});

it('exposes transactionId via interface method', function () {
    $three = new ThreeDSChallenge(transactionId: 'txn-a');
    $redirect = new RedirectChallenge(transactionId: 'pay-b', url: 'https://x', formFields: []);

    expect($three->transactionId())->toBe('txn-a')
        ->and($redirect->transactionId())->toBe('pay-b');
});
