<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Subscription\Command;

use Techork\PaymentService\Domain\Subscription\ValueObject\CancellationTiming;
use Techork\PaymentService\Domain\Subscription\ValueObject\SubscriptionId;

interface CancelSubscriptionCommand
{
    public function subscriptionId(): SubscriptionId;

    public function reason(): string;

    /**
     * Whether the subscriber keeps the rest of the period.
     *
     * The intent, not the instant — the aggregate works out the date, because only it
     * knows whether a period exists. What it cannot know is whether that period was ever
     * paid for: a signup activated on a payment the checkout then failed to capture looks
     * exactly like one that settled. {@see CancellationTiming::Immediately} is how the
     * caller says so.
     */
    public function timing(): CancellationTiming;
}
