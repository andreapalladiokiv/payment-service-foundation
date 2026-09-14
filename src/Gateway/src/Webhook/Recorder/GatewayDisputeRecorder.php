<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Webhook\Recorder;

use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Records a case a provider has raised, or decided, against our side of it.
 *
 * Disputes enter through three calls rather than one, and the difference is not cosmetic — each is
 * keyed on whatever the delivery actually carries.
 *
 * ## Why `onDisputeObserved` and `onDisputeResolved` are keyed differently
 *
 * `onDisputeObserved` is reached *through* a PaymentIntent: a Stripe `charge.dispute.created`, a
 * Nuvei chargeback DMN or a ConnexPay poll has already been matched to one of our payments, so the
 * PaymentIntent is the key and the provider's reference for the case rides inside the snapshot —
 * required there, see {@see DisputeSnapshot}, because it is the only thing that identifies which of
 * the payment's cases this delivery is about.
 *
 * `onDisputeResolved` cannot be keyed that way. A resolution may arrive for a case whose
 * PaymentIntent never resolved, or after a backlog import, and the provider names the case rather
 * than the event, so **the provider's reference itself is the key**. Without that, a resolution
 * would land on nothing exactly in the case that most needs it — a case that was already lost
 * before we ever saw it.
 *
 * Together those two facts are the contract: a snapshot always carries its reference, and a
 * resolution is addressed by one. The reference is what ties the calls to one dispute.
 *
 * ## What implementations do with the flat DTOs
 *
 * Nothing in these DTOs is a domain value object — see the class docblocks on the three of them for
 * why — so the mapping onto the aggregate's vocabulary (the stage, the status, the fee types, the
 * deadline) happens in the implementation, in the application or in `src/Laravel/`. `RecorderOutcome::NotFound` is how an implementation says "this case has not
 * been observed yet, retry", which is the ordinary answer on a delivery that arrives before the
 * state it depends on.
 *
 * The brand is the one field outside that division, and deliberately so. It arrives already
 * established, because it is not a matter of vocabulary — every adapter can state it, and the one
 * whose payload carries none (Nuvei) reads it off the reason code's namespace before building the
 * snapshot. None of that needs a domain value, and a recorder that had to derive a network would
 * be deriving it from a code the provider sent rather than from anything it owns. See
 * {@see DisputeSnapshot}.
 *
 * ## The one case that is not attached to anything
 *
 * `onUnmatchedDispute` is ConnexPay's, and only theirs — their poller can surface a case whose
 * `OrderNumber` and `Arn` match nothing of ours. It is deliberately not a fourth outcome of the
 * observed call: the caller has no PaymentIntent to pass, so a call that must name one cannot
 * express it.
 */
interface GatewayDisputeRecorder
{
    /**
     * A case we can attach to a PaymentIntent. `$snapshot` always carries the provider's reference.
     *
     * `$paymentIntentId` is our internal aggregate id (a uuid string), already resolved by the
     * caller through `TransactionIdResolver` — the same division of labour as
     * {@see GatewayFeeRecorder}. An implementation resolves our dispute aggregate from the
     * snapshot's reference (and, where the provider re-references its cases, its family) rather
     * than from the payment: a payment can carry several disputes, at Stripe one per case, and
     * pairing them the other way round would merge two cases into one aggregate.
     */
    public function onDisputeObserved(GatewayId $gatewayId, string $paymentIntentId, DisputeSnapshot $snapshot): RecorderOutcome;

    /**
     * A case the networks decided, addressed by the provider's own reference for it.
     *
     * Keyed on the reference because a resolution may arrive with no PaymentIntent in it, and on
     * `$gatewayId` as well because a reference is only unique inside one gateway account — the same
     * rule `TransactionIdResolver` applies to every other provider reference.
     */
    public function onDisputeResolved(GatewayId $gatewayId, string $disputeRef, DisputeResolution $resolution): RecorderOutcome;

    /**
     * ConnexPay only: a case that matched no payment of ours, surfaced instead of dropped.
     *
     * `RecorderOutcome::Skipped` is the right answer when the case has already been reported; the
     * caller stores the case's key either way, so a case an operator has already been given is not
     * given again on the next poll.
     */
    public function onUnmatchedDispute(GatewayId $gatewayId, UnmatchedDispute $case): RecorderOutcome;
}
