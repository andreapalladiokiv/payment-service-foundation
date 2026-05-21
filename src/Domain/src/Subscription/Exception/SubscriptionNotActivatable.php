<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Subscription\Exception;

use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentStatus;
use Techork\PaymentService\Domain\Subscription\SubscriptionStatus;

final class SubscriptionNotActivatable extends \DomainException
{
    public static function withStatus(SubscriptionStatus $status): self
    {
        return new self("Subscription cannot be activated in status [{$status->value}].");
    }

    public static function paymentIntentNotCharged(PaymentIntentStatus $status): self
    {
        return new self("Subscription activation requires a charged payment intent (got [{$status->value}]).");
    }

    public static function amountMismatch(): self
    {
        return new self('Payment intent amount does not match subscription plan amount.');
    }
}
