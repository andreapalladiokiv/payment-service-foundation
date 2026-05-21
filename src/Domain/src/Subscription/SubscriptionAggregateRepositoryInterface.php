<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Subscription;

use Techork\PaymentService\Domain\Subscription\ValueObject\SubscriptionId;

interface SubscriptionAggregateRepositoryInterface
{
    public function retrieve(SubscriptionId $aggregateRootId): SubscriptionAggregate;

    public function persist(SubscriptionAggregate $aggregateRoot): void;
}
