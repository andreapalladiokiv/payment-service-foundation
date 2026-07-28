<?php

declare(strict_types=1);

namespace Techork\PaymentService\Forter;

/**
 * The recommendation a fraud-screening provider (e.g. Forter) returns for a
 * transaction. This is the provider's raw opinion, NOT the final action — it is
 * exposed to the firewall as a fact, so operator-configured rules can weigh it
 * alongside everything else before a decision is reached (see
 * {@see \Techork\PaymentService\Domain\PaymentIntent\Port\PaymentIntentFirewallPort}).
 * A decline here therefore never blocks a payment on its own.
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
