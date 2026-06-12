<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Port;

use Money\Money;

/**
 * Result of a {@see CapturePort::capture()} call. `convertedAmount` is the
 * amount credited to our merchant account after FX. Adapters that don't
 * know the converted figure default it to the requested capture amount.
 */
final readonly class CaptureOutcome
{
    public function __construct(
        public Money $convertedAmount,
    ) {}
}
