<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Checkout\Exception;

use Techork\PaymentService\Domain\Checkout\CheckoutStatus;

final class CheckoutCannotBeCancelled extends \DomainException
{
    public static function withStatus(CheckoutStatus $status): self
    {
        return new self("Checkout cannot be cancelled in status [{$status->value}].");
    }
}
