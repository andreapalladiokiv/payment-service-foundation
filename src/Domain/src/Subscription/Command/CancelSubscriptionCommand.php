<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Subscription\Command;

use Techork\PaymentService\Domain\Subscription\ValueObject\SubscriptionId;

interface CancelSubscriptionCommand
{
    public function subscriptionId(): SubscriptionId;

    public function reason(): string;
}
