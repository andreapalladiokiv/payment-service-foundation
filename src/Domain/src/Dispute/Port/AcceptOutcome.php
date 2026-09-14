<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\Port;

use InvalidArgumentException;
use Money\Money;

/**
 * What the provider did with a concession — which is not always what we asked for.
 *
 * Not `void`, because "we closed the case" is an assumption the caller would have to make and one
 * that is wrong often enough to matter. The three facts below are three different things to
 * record:
 *
 * ```
 * accepted in full     the case is conceded; the disputed amount is not coming back
 * accepted partially   a stated part of it is conceded — Nuvei's PARTIAL
 * already closed       the provider had closed the case before our call; nothing changed here
 * ```
 *
 * The third is the one that would be silently lost by a `void`: a case that was resolved by the
 * network, or by a previous accept whose response we never saw, would be recorded as though our
 * call had done it — and the ledger would book a concession we did not make at a moment we did not
 * make it.
 *
 * ## Private, because the three answers are not built from fields
 *
 * A public constructor would take "an amount, maybe, and a flag" and let a caller produce
 * combinations that mean nothing — an amount beside `already closed`, or a null amount that could
 * be either a full acceptance or an unknown one. The factories are the whole vocabulary, and
 * {@see self::acceptedAmount()} returning null means the *full* disputed amount rather than an
 * unstated one.
 */
final readonly class AcceptOutcome
{
    private function __construct(
        private ?Money $acceptedAmount,
        private bool $alreadyClosed,
    ) {}

    /** The whole case is conceded. */
    public static function acceptedInFull(): self
    {
        return new self(null, false);
    }

    /**
     * A stated part of the case is conceded — Nuvei's `PARTIAL`.
     *
     * The amount is what the provider accepted, not what we offered: where a provider answers with
     * the figure it settled on, that figure is the fact worth recording.
     */
    public static function acceptedPartially(Money $amount): self
    {
        $amount->isPositive() || throw new InvalidArgumentException(
            "A partial acceptance was reported for {$amount->getAmount()} "
            . "{$amount->getCurrency()->getCode()}. The case is conceded rather than nothing, so a "
            . 'non-positive figure is a mapping bug in the adapter that read the provider.',
        );

        return new self($amount, false);
    }

    /**
     * The provider had already closed the case.
     *
     * Our call did not decide this outcome, so the aggregate must not record a concession from it.
     */
    public static function alreadyClosed(): self
    {
        return new self(null, true);
    }

    /** Whether our call closed the case, in whole or in part. */
    public function wasAccepted(): bool
    {
        return ! $this->alreadyClosed;
    }

    public function wasAlreadyClosed(): bool
    {
        return $this->alreadyClosed;
    }

    /**
     * The figure the provider accepted, or null when the whole disputed amount was accepted.
     *
     * Null is never "unknown": an unknown outcome is {@see self::alreadyClosed()}, which
     * {@see self::wasAccepted()} reports separately, and the disputed amount the caller already
     * holds is what null resolves to.
     */
    public function acceptedAmount(): ?Money
    {
        return $this->acceptedAmount;
    }

    /** Whether less than the disputed amount was conceded. */
    public function wasPartial(): bool
    {
        return $this->acceptedAmount !== null;
    }
}
