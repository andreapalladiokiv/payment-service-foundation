<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Subscription\Exception;

use Techork\PaymentService\Domain\Subscription\SubscriptionStatus;

final class SubscriptionNotCancellable extends \DomainException
{
    public static function withStatus(SubscriptionStatus $status): self
    {
        return new self("Subscription cannot be cancelled in status [{$status->value}].");
    }

    public static function alreadyScheduled(): self
    {
        return new self('Subscription cancellation is already scheduled.');
    }

    public static function notScheduled(): self
    {
        return new self('Subscription cancellation is not scheduled.');
    }

    public static function alreadyPending(): self
    {
        return new self('Subscription cancellation is already pending.');
    }
}
