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

    /**
     * Authorized, not charged: activation is what decides the money may be
     * taken, so it must still be takeable. An intent charged inline at create
     * moved the money before any of these checks ran, and the same one could
     * then activate a second subscription with nothing left to refuse.
     */
    public static function paymentIntentNotAuthorized(PaymentIntentStatus $status): self
    {
        return new self("Subscription activation requires an authorized payment intent (got [{$status->value}]).");
    }

    public static function amountMismatch(): self
    {
        return new self('Payment intent amount does not match subscription plan amount.');
    }
}
