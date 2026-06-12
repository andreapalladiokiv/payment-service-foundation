<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Port;

use Money\Money;
use Techork\PaymentService\Common\Contract\Challenge;

/**
 * Result of a {@see CreatePort::create()} call.
 *
 * `challenge` is non-null when the gateway requested a step-up (3DS / hosted
 * redirect); the aggregate parks at `RequiresAction` and ignores
 * `convertedAmount` until the challenge resolves.
 *
 * `convertedAmount` is the amount actually credited to our merchant account
 * once any FX was applied. It's null on authorize-only flows because no
 * settlement has happened yet; on the inline-charge (Immediate) success
 * path it MUST be set — adapters that can't read it from the gateway
 * response fall back to the requested amount.
 */
final readonly class CreateOutcome
{
    public function __construct(
        public ?Money $convertedAmount = null,
        public ?Challenge $challenge = null,
    ) {}
}
