<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject\Risk;

/**
 * The recommendation a fraud-screening provider (e.g. Forter) returns for a
 * transaction. This is the provider's raw opinion, NOT the final action —
 * the {@see \Techork\PaymentService\Domain\PaymentIntent\Port\RiskDecisionPort}
 * combines it with operator-configured rules to decide whether to step up to
 * 3DS, skip it, or allow. A decline here therefore never blocks a payment on
 * its own.
 *
 *  - {@see Approve}     provider saw no fraud signal.
 *  - {@see Decline}     provider flagged the transaction as fraudulent.
 *  - {@see NotReviewed} provider returned without a verdict (below review
 *                       threshold, sampling, or "not reviewed" response).
 *  - {@see Errored}     the screening could not be completed (provider
 *                       unavailable, timeout, malformed response). The
 *                       decision layer applies its fail-open/closed policy.
 */
enum FraudDecision: string
{
    case Approve = 'approve';
    case Decline = 'decline';
    case NotReviewed = 'not_reviewed';
    case Errored = 'errored';
}
