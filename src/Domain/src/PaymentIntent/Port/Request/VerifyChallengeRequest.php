<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Port\Request;

use Money\Money;
use Techork\PaymentService\Common\Contract\ChallengeResult;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;

/**
 * An authentication result somebody has presented, to be checked before it is acted on.
 *
 * This exists because the result arrives from outside. A caller creating a payment may attach
 * the outcome of an authentication it ran earlier, and the domain has no way to tell that
 * outcome from a well-formed invention: the coherence check that runs on it asks whether the
 * fields agree with each other, not whether the authentication happened. Meanwhile the evidence
 * is what claims the liability shift, and — since the firewall is consulted on every payment now
 * — presenting one is what stands between a step-up rule and the acquirer.
 *
 * So a chain that demands a step-up and is handed a finished one asks the provider rather than
 * the presenter. Skipping the check remains available and becomes a decision an implementation
 * makes out loud, by answering {@see \Techork\PaymentService\Domain\PaymentIntent\Port\ChallengeOutcome::passed()}
 * with what it was given.
 *
 * The instrument comes along because an authentication belongs to a card: a result that is
 * genuine but was obtained for a different one is not evidence about this payment.
 */
final readonly class VerifyChallengeRequest
{
    public function __construct(
        public PaymentIntentId $paymentIntentId,
        public ChallengeResult $presented,
        public Money $amount,
        public PaymentInstrument $instrument,
        public ?string $reason = null,
    ) {}
}
