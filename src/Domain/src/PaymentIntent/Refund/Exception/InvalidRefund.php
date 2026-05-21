<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Refund\Exception;

use Money\Currency;

final class InvalidRefund extends \DomainException
{
    public static function nonPositiveAmount(): self
    {
        return new self('Refund amount must be positive.');
    }

    public static function currencyMismatch(Currency $expected, Currency $actual): self
    {
        return new self("Refund currency [{$actual->getCode()}] does not match payment intent currency [{$expected->getCode()}].");
    }
}
