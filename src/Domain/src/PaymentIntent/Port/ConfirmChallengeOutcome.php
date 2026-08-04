<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Port;

use Money\Money;
use Techork\PaymentService\Common\Contract\Challenge;

/**
 * Result of a {@see ConfirmChallengePort::confirm()} call.
 *
 * Both properties are empty on the ordinary webhook path, where the gateway
 * settled the payment itself and the port made no call at all.
 *
 * `challenge` is non-null when clearing one authentication produced another — a
 * gateway that answers the payment it was just handed with a step-up of its own.
 * The aggregate parks again and waits for that one.
 *
 * `convertedAmount` is the amount credited to our merchant account after any FX,
 * and only an adapter that placed the payment can know it.
 */
final readonly class ConfirmChallengeOutcome
{
    public function __construct(
        public ?Money $convertedAmount = null,
        public ?Challenge $challenge = null,
    ) {}
}
