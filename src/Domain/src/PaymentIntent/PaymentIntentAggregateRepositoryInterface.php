<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent;

use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;

interface PaymentIntentAggregateRepositoryInterface
{
    public function retrieve(PaymentIntentId $aggregateRootId): PaymentIntentAggregate;

    public function persist(PaymentIntentAggregate $aggregateRoot): void;
}
