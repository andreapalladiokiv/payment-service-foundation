<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\ValueObject;

/**
 * Which fee was charged, and therefore which way it points.
 *
 * The amount on a {@see DisputeFee} is always positive as charged; the direction is this
 * enum's business and the ledger's (A4). That split is what makes the two Stripe fees and the
 * four ConnexPay ones expressible in one list: Stripe debits a dispute-*received* fee at
 * initiation, non-refundable outside Mexico, and returns the dispute-*countered* fee on a win;
 * ConnexPay charges $20 on an incoming chargeback, $20 on a retrieval request, $20 on an
 * incoming pre-arbitration, $20 on a reversal acceptance — which is a win — and nothing at all
 * on a reversal denial. Same list, different signs, decided by whoever reads the type.
 *
 * A single `Money disputeFee` field could not carry that, which is why the aggregate holds a
 * list. A4 cannot be built from a number that has already lost the difference between a fee we
 * paid and a fee we got back.
 */
enum FeeType: string
{
    /** Debited when a chargeback is raised against us. Stripe's "dispute received". */
    case Chargeback = 'chargeback';

    /** Debited for a retrieval request or an inquiry — information asked for, no chargeback yet. */
    case Retrieval = 'retrieval';

    /** Debited when the case escalates into pre-arbitration. */
    case PreArbitration = 'pre_arbitration';

    /** Charged on a reversal we accepted — i.e. on a win. ConnexPay's $20 reversal acceptance. */
    case ReversalAcceptance = 'reversal_acceptance';

    /** Returned to us on a win. Stripe's "dispute countered". */
    case Countered = 'countered';
}
