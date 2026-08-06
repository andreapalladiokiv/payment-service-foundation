<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Exception;

use LogicException;

/**
 * A {@see \Techork\PaymentService\Domain\PaymentIntent\Port\PaymentIntentFirewallPort}
 * implementation broke the contract it is documented to keep.
 *
 * Deliberately not a `DomainException`, unlike everything else in this directory. Those describe
 * a payment that cannot be made — a non-positive amount, a card that is spent, a capture method
 * the instrument cannot support — and an application converts them into a refusal the caller
 * asked for. This one describes a collaborator that answered with something it promised not to
 * answer with, which is a defect in the code, not an outcome of the payment. Catching it as a
 * business result would report a perfectly ordinary payment as refused and hide the bug.
 */
final class FirewallPortViolation extends LogicException
{
    /**
     * The firewall demanded a challenge and supplied none.
     *
     * The aggregate would otherwise record `PaymentIntentRequiresAction` with nothing to
     * present: an intent parked forever, with no client able to act and no way to tell it from a
     * step-up still in flight. The engine that ships with this project throws before it can
     * happen, so reaching here means a hand-rolled port — and the fix is in that port, either by
     * obtaining a real challenge or by refusing.
     */
    public static function challengeDemandedWithoutOne(?string $reason): self
    {
        return new self(sprintf(
            'The firewall required a challenge (%s) but supplied none — a payment cannot be parked on a challenge that does not exist.',
            $reason ?? 'no reason given',
        ));
    }
}
