<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\Port\Request;

use InvalidArgumentException;
use Money\Money;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeId;

/**
 * The case to concede, and — where the provider takes one — the amount.
 *
 * The case is named by **our own id**, and the adapter turns it into whatever its provider
 * addresses a case with: the mapping from a `DisputeId` to the provider's reference is a row in
 * `gateway_references`, and the reference table is the adapter's to read. Nothing here carries the
 * provider's own name for the case, which is what keeps a value the Domain has no other use for
 * out of the aggregate's vocabulary.
 *
 * `$partialAmount` is null for a full acceptance, which is the only acceptance Stripe has: its
 * `close` concedes the whole case, and asking it for a part is not a request it can be given.
 * Nuvei's `PARTIAL` is the other shape. The amount is a decision taken at the call rather than at
 * the read that proposed it — the action that offered it carries the *ceiling*
 * ({@see \Techork\PaymentService\Domain\Dispute\ValueObject\AcceptDisputeAction::$disputedAmount}),
 * which is what is at stake and not what we are giving up, and the difference between the two is the
 * whole of this field.
 *
 * **A partial amount the provider cannot honour is refused, never rounded up.** An adapter that
 * receives a non-null amount and has no partial acceptance must fail the call rather than concede
 * the whole case: accepting fully is irreversible, and the difference is the entire disputed amount.
 * This file only refuses an amount that is not an amount — a non-positive one is a caller bug
 * whichever provider reads it.
 */
final readonly class AcceptDisputeRequest
{
    public function __construct(
        public DisputeId $disputeId,
        public ?Money $partialAmount = null,
    ) {
        $partialAmount === null || $partialAmount->isPositive() || throw new InvalidArgumentException(
            "A partial acceptance was requested for {$partialAmount->getAmount()} "
            . "{$partialAmount->getCurrency()->getCode()}. Accepting part of a case concedes money, "
            . 'so a non-positive amount is a caller bug rather than a concession of nothing.',
        );
    }
}
