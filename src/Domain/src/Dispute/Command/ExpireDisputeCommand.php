<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\Command;

use DateTimeImmutable;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeId;

/**
 * The application's clock declaring the response window closed.
 *
 * The only command on this aggregate with no {@see \Techork\PaymentService\Domain\Dispute\ValueObject\DisputeSignal},
 * and the reason is the point of it existing at all: expiry has two routes — a provider saying so
 * in a signal, and our own clock noticing that nobody acted. The aggregate has no timer of its
 * own (the stored deadline reads no clock either), so the moment is supplied by the caller that
 * owns one.
 *
 * Repeating it is harmless: an already-expired case has no transition to `EXPIRED` and records
 * nothing.
 */
interface ExpireDisputeCommand
{
    public function disputeId(): DisputeId;

    public function at(): DateTimeImmutable;
}
