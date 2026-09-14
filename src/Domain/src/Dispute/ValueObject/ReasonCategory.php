<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\ValueObject;

/**
 * What a reason code is *about*, in the networks' own vocabulary rather than any one network's.
 *
 * This is a derived hint, not the identity of the reason. The identity is the pair
 * `(CardBrand, rawCode)` that {@see DisputeReason} stores, because evidence requirements differ
 * between networks for reasons that read the same and the hint logic hangs off the pair. The
 * category exists so that a rule that is genuinely network-neutral — "a not-received dispute
 * wants a proof of delivery" — does not have to enumerate every network's code for the same
 * idea.
 *
 * The names are Stripe's, where Stripe has one: it is the one dispute vocabulary in the fleet
 * that is already network-neutral, an SDK author has read it, and matching it costs nothing.
 */
enum ReasonCategory: string
{
    /** The cardholder says they did not authorise it. */
    case Fraud = 'fraud';

    /** Something about the authorisation: missing, declined, or not obtainable. */
    case Authorization = 'authorization';

    /** Paid for and never received, or only partly received. */
    case NotReceived = 'not_received';

    /** Received and not as described, defective, or counterfeit. */
    case NotAsDescribed = 'not_as_described';

    /** A subscription or an order the cardholder says they cancelled. */
    case Cancelled = 'cancelled';

    /** Charged twice for one thing. */
    case Duplicate = 'duplicate';

    /** The amount charged is not the amount agreed. */
    case IncorrectAmount = 'incorrect_amount';

    /** A credit the cardholder was promised that never arrived. */
    case CreditNotProcessed = 'credit_not_processed';

    /** The network's own processing went wrong — late presentment, bad data, a wrong transaction code. */
    case ProcessingError = 'processing_error';

    /**
     * A code this package's table does not know.
     *
     * Deliberately not an error. A network adds a reason code without asking us, and a live
     * chargeback with money already debited and a deadline running must not fail to be ingested
     * because the code is new — the raw code and the brand are preserved on
     * {@see DisputeReason}, so nothing about the provider's statement is lost, and the category
     * being absent is a statement about *our* table rather than about the case. The alternative,
     * refusing the delivery, loses a case with a deadline in order to make a point about a
     * lookup table.
     */
    case Uncategorised = 'uncategorised';
}
