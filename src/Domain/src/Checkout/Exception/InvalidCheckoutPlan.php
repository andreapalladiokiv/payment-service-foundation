<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Checkout\Exception;

final class InvalidCheckoutPlan extends \DomainException
{
    public static function amountMismatch(): self
    {
        return new self('Subscription plan amount must equal the checkout amount.');
    }
}
