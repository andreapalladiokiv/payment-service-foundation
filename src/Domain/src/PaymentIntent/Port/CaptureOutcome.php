<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Port;

use Money\Money;

/**
 * Result of a {@see CapturePort::capture()} call. `convertedAmount` is the
 * amount credited to our merchant account after FX, or null when the gateway
 * applied no conversion (or the adapter carries no such signal).
 */
final readonly class CaptureOutcome
{
    public function __construct(
        public ?Money $convertedAmount = null,
    ) {}
}
