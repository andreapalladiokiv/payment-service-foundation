<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Exception;

final class InvalidPaymentIntent extends \DomainException
{
    public static function nonPositiveAmount(): self
    {
        return new self('Payment intent amount must be positive.');
    }

    public static function unusablePaymentSource(): self
    {
        return new self('Payment source is not usable (expired or consumed).');
    }
}
