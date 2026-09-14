<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute;

/**
 * Where the case sits in the networks' chargeback cycle.
 *
 * Four values, and that is the whole point: the *position* inside a stage — a first
 * chargeback versus a second, a representment versus a reversal — is not here, it rides in the
 * events that recorded the move as `providerCode`, which is where a projection of the case's own
 * history reads it. ConnexPay says the
 * same thing with a `CaseType` table that runs to 24 entries while the cycle it describes has
 * only these four stages, and Nuvei's unified status codes (`FC`, `FC-M-RJCT`, `IPA-M-*`)
 * collapse onto them the same way. A stage that carried the cycle position would need a case
 * per provider, and then the aggregates could not be compared across gateways at all.
 *
 * ## `Arbitration` has no producer, deliberately
 *
 * The value is here because the networks have the phase and the deferred outbound-dispute
 * aggregate (D1) may one day reach it — not because anything in this service maps to it.
 * Stripe does not support arbitration at all, Nuvei's own reference tops out at pre-arbitration
 * (`IPA`), and ConnexPay's `CaseType` table stops at 9 and 24, both pre-arbitration.
 *
 * So no adapter may map a provider code onto it, and **no invariant here may assume a dispute
 * can arrive in it**. A provider signal that would land there is an unmapped case — an operator
 * has to see it — and mapping an unrecognised code to `Arbitration` because it is "the last
 * one in the enum" is exactly the silent default this comment exists to forbid. If D1 does
 * reach the phase it gets its own aggregate, and this case will have to be reconsidered then.
 */
enum DisputeStage: string
{
    /** A pre-chargeback request for information. Amex and Discover still use it; Visa and Mastercard do not. */
    case Inquiry = 'inquiry';

    case Chargeback = 'chargeback';

    case PreArbitration = 'pre_arbitration';

    /**
     * Unreachable from any adapter in this service — see the class docblock. Present so that a
     * future producer does not have to invent a fourth spelling of the same phase.
     */
    case Arbitration = 'arbitration';
}
