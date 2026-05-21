<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Exception;

use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentStatus;

final class PaymentIntentCannotBeCaptured extends \DomainException
{
    public static function withStatus(PaymentIntentStatus $status): self
    {
        return new self("PaymentIntent cannot be captured in status [{$status->value}].");
    }

    public static function immediate(): self
    {
        return new self('PaymentIntent capture_method is immediate; capture happens inline at create.');
    }
}
