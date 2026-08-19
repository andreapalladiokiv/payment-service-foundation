<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject\Challenge;

use Override;
use Techork\PaymentService\Common\Contract\Challenge;
use Techork\PaymentService\Common\Contract\ChallengeVisitor;

/**
 * A step-up the provider's own SDK conducts in the payer's browser. There is nowhere to send
 * anyone.
 *
 * The other two challenges are addresses: {@see ThreeDSChallenge} posts to an ACS,
 * {@see RedirectChallenge} sends the browser to a hosted page. This one is the third thing
 * the wire actually says — Stripe's `use_stripe_sdk`, where Stripe.js runs the authentication
 * in an iframe on a page of the caller's and asks only for a handle on the payment.
 *
 * Modelling it as an address was the mistake this replaces. An address was minted from a
 * configured prefix, which obliged the caller to host a page whose only job was to receive
 * that address and start the SDK, and made a payment fail outright when no prefix was
 * configured — demanding an address for the one shape that has no use for one.
 *
 * **No client secret here, deliberately.** A challenge rides
 * {@see \Techork\PaymentService\Domain\PaymentIntent\Event\PaymentIntentRequiresAction} into an
 * append-only stream and out into read models, and whoever holds Stripe's client secret can
 * confirm the payment — so carrying it would write a live credential into a log that never
 * forgets. `$paymentReference` is the provider's own id for the payment, not a secret; the
 * caller fetches the secret with its own API key, server-side, and hands it to the one browser
 * that is about to authenticate.
 *
 * Nor is the answer a field the serializer quietly drops. That is already tried in
 * {@see \Techork\PaymentService\Common\ValueObject\CreditCard\Cvc}, whose own docblock records
 * where it leads: the value returns null after a round-trip, and the caller finds out somewhere
 * far from here.
 *
 * `$authenticationId` is the protocol's identity for the authentication — for Stripe the
 * `server_transaction_id` under `next_action.use_stripe_sdk`, which is the `threeDSServerTransID`
 * the directory server keeps too, so a later result can be matched against it exactly as one
 * from {@see ThreeDSChallenge} can.
 */
final readonly class SdkChallenge implements Challenge
{
    public function __construct(
        public string $authenticationId,
        public string $paymentReference,
    ) {}

    #[Override]
    public function transactionId(): string
    {
        return $this->authenticationId;
    }

    /** @inheritDoc */
    #[Override]
    public function accept(ChallengeVisitor $visitor): mixed
    {
        return $visitor->visitSdk($this);
    }
}
