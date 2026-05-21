<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Subscription\Exception;

use Techork\PaymentService\Domain\Subscription\SubscriptionStatus;

final class SubscriptionNotRenewable extends \DomainException
{
    public static function withStatus(SubscriptionStatus $status): self
    {
        return new self("Subscription cannot be renewed in status [{$status->value}].");
    }

    public static function pendingCancellation(): self
    {
        return new self('Subscription cannot be renewed while a cancellation is pending.');
    }
}
