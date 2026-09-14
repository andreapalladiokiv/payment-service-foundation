<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\Command;

use DateTimeImmutable;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeId;

/**
 * Our decision to concede the case.
 *
 * A command of its own rather than a status value because `ACCEPTED` records *why* we stopped
 * fighting, and the provider's own API does not know that — it reports the case as `lost`, which
 * is what the same case would say if the issuer had decided against us.
 *
 * It carries a moment rather than a {@see \Techork\PaymentService\Domain\Dispute\ValueObject\DisputeSignal}
 * because there is no provider delivery behind it: F7's close is our own call, and its response
 * names the case rather than the event. Repeating the command is harmless — a case already
 * `ACCEPTED` has nothing to record — and that state check is what stands in for a key.
 */
interface AcceptDisputeCommand
{
    public function disputeId(): DisputeId;

    public function at(): DateTimeImmutable;
}
