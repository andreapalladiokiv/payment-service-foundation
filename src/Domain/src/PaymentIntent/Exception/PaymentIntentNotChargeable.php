<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Exception;

use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentStatus;

final class PaymentIntentNotChargeable extends \DomainException
{
    public static function withStatus(PaymentIntentStatus $status): self
    {
        return new self("PaymentIntent cannot be charged in status [{$status->value}].");
    }
}
