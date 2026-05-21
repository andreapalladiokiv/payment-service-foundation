<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Subscription\Command;

use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentAggregate;
use Techork\PaymentService\Domain\Subscription\ValueObject\SubscriptionId;

interface ActivateSubscriptionCommand
{
    public function subscriptionId(): SubscriptionId;

    public function periodStart(): \DateTimeImmutable;

    public function paymentIntent(): PaymentIntentAggregate;
}
