<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Exception;

use Money\Money;

final class PaymentIntentRefundExceedsAmount extends \DomainException
{
    public static function create(Money $availableAmount, Money $requestedAmount): self
    {
        return new self(
            "Refund amount [{$requestedAmount->getAmount()}] exceeds available amount [{$availableAmount->getAmount()}]."
        );
    }
}
