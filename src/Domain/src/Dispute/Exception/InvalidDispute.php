<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\Exception;

use DomainException;
use Money\Money;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeId;

/**
 * A dispute cannot be built, or cannot be built from what it was handed.
 *
 * ## Why this one has no `ErrorCode`
 *
 * Every other refusal in this package is a {@see \Techork\PaymentService\Common\Contract\CodedError},
 * and this one is not, and the reason is not a judgement about which is better. A coded refusal
 * needs a case on {@see \Techork\PaymentService\Common\ValueObject\ErrorCode}, that enum is
 * documented to collapse state problems per resource — "one `PaymentIntentUnexpectedState` rather
 * than separate codes for capture, cancel and refund" — and the dispute resource has no such case
 * because the resource is new. There is no code here that means what this exception means:
 * `PaymentIntentUnexpectedState` would name the wrong resource and `Unspecified` is documented as
 * "only ever read back, never written".
 *
 * So this is a plain `DomainException`, like {@see \Techork\PaymentService\Domain\PaymentIntent\Exception\ChallengeCannotBeRaised},
 * and the fix is one line in `Common`: a `DisputeUnexpectedState = 'dispute_unexpected_state'`
 * case plus its row in {@see \Techork\PaymentService\Common\ValueObject\ErrorCode}'s test data.
 * That edit is deliberately not made here — this task creates files under `Dispute/` and nothing
 * else — and until it is made an application mapping dispute refusals onto its own error surface
 * has to classify them itself.
 *
 * ## An empty key is a hard error, and that is the point
 *
 * {@see self::emptyProviderEventKey()} states one invariant: **the idempotency key may not be
 * empty**. The key is the provider's own key for the delivery, which arrives with the delivery and
 * is named by no value object of ours, and an empty one is a *constant* — and a constant matches
 * the second delivery exactly as well as the first. So the guard meant to suppress repeats instead
 * suppresses the first genuinely new fact that arrives, silently, and the case looks like one the
 * provider never mentioned again. There is no safe default here, which is why none is offered.
 */
final class InvalidDispute extends DomainException
{
    public static function emptyProviderEventKey(): self
    {
        return new self(
            'A dispute signal arrived with no provider event key. It is the idempotency key, and '
            . 'an empty one would make every delivery of this case look identical to the first — '
            . 'the whole of the state would be dropped as a duplicate.',
        );
    }

    public static function emptyReasonCode(): self
    {
        return new self(
            'A dispute reason was built with a blank raw code. The (brand, raw code) pair is what '
            . 'the evidence requirements and the hint logic key off; a blank one would make every '
            . 'unrecognised case look alike, and the case would be answered with the wrong '
            . 'template.',
        );
    }

    public static function nonPositiveFee(Money $amount): self
    {
        return new self(
            "A dispute fee of {$amount->getAmount()} {$amount->getCurrency()->getCode()} was "
            . 'recorded. Fees are stored positive as charged and the direction is the fee type\'s '
            . 'business, so a non-positive amount would carry a sign that contradicts the type it '
            . 'was filed under.',
        );
    }

    public static function nonPositiveDisputedAmount(Money $amount): self
    {
        return new self(
            "A dispute was opened for {$amount->getAmount()} {$amount->getCurrency()->getCode()}. "
            . 'The disputed amount is what the network is holding, and a case for nothing is a '
            . 'mapping bug rather than a dispute.',
        );
    }

    public static function alreadyOpen(DisputeId $id): self
    {
        return new self(
            "Dispute {$id->toString()} is already open and cannot be opened over. Reopening it "
            . 'would rewrite its stage, reason and amount from a delivery that may be older than '
            . 'the state the case has already reached.',
        );
    }

    /**
     * A provider signal would put the case in `ARBITRATION`.
     *
     * No adapter maps to that stage — Stripe does not support the phase, Nuvei's highest code is
     * `IPA` and ConnexPay's `CaseType` tops out at pre-arbitration — so a signal carrying it is a
     * mapping bug in the adapter, and one that this aggregate cannot absorb as though it were an
     * ordinary next stage. The value stays on {@see \Techork\PaymentService\Domain\Dispute\DisputeStage}
     * because the networks have the phase and D1 may reach it, but until an adapter exists that
     * can produce it this is refused loudly for an operator to read.
     */
    public static function arbitrationHasNoProducer(): self
    {
        return new self(
            'A dispute signal would put the case into ARBITRATION, which no adapter maps to: '
            . 'Stripe does not support the phase, Nuvei stops at pre-arbitration and ConnexPay\'s '
            . 'CaseType table stops there too. This is an unmapped case in the adapter that built '
            . 'the signal, not a stage to record.',
        );
    }

    /**
     * `ACCEPTED` is our own deliberate act, and a provider cannot report it.
     *
     * It records *why* we stopped fighting — F7's close concedes, and the provider then reports
     * the same case as `lost` because its API knows only that the merchant stopped. So a provider
     * signal naming `ACCEPTED` is either an adapter leaking our own vocabulary into a provider
     * mapping or a case being closed for a reason we did not choose, and either way it is not the
     * fact {@see \Techork\PaymentService\Domain\Dispute\DisputeAggregate::accept()} records.
     */
    public static function acceptanceIsNotAProviderSignal(): self
    {
        return new self(
            'A provider signal asked for ACCEPTED, which is our own deliberate concession rather '
            . 'than something a provider reports — its API knows only that the merchant stopped '
            . 'fighting. Use DisputeAggregate::accept() to concede a case; a signal naming ACCEPTED '
            . 'is a mapping bug.',
        );
    }

}
