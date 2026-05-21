<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Subscription;

enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case Cancelled = 'cancelled';

    public function isCancellable(): bool
    {
        return $this !== self::Cancelled;
    }

    public function isRenewable(): bool
    {
        return $this === self::Active;
    }
}
