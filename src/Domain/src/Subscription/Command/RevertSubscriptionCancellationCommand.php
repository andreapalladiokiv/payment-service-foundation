<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Subscription\Command;

use Techork\PaymentService\Domain\Subscription\ValueObject\SubscriptionId;

interface RevertSubscriptionCancellationCommand
{
    public function subscriptionId(): SubscriptionId;
}
