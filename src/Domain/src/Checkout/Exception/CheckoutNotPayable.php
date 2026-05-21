<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Checkout\Exception;

use Techork\PaymentService\Domain\Checkout\CheckoutStatus;
use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentStatus;

final class CheckoutNotPayable extends \DomainException
{
    public static function withStatus(CheckoutStatus $status): self
    {
        return new self("Checkout cannot be paid in status [{$status->value}].");
    }

    public static function expired(): self
    {
        return new self('Checkout has expired.');
    }

    public static function paymentIntentNotAuthorized(PaymentIntentStatus $status): self
    {
        return new self("Payment intent is not authorized (status: $status->value).");
    }

    public static function amountMismatch(): self
    {
        return new self('Payment intent amount does not match checkout amount.');
    }

    public static function planSubscriptionMismatch(): self
    {
        return new self('Checkout plan and PayCheckoutCommand subscription must both be set or both be null.');
    }

    public static function subscriptionCancelled(): self
    {
        return new self('Cannot pay a checkout against a cancelled subscription.');
    }

    public static function paymentIntentSubscriptionMismatch(): self
    {
        return new self('Payment intent is not the one bound to the subscription.');
    }
}
