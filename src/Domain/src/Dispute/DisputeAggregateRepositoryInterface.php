<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute;

use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeId;

interface DisputeAggregateRepositoryInterface
{
    public function retrieve(DisputeId $aggregateRootId): DisputeAggregate;

    public function persist(DisputeAggregate $aggregateRoot): void;
}
