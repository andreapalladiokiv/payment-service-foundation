<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Customer;

use Techork\PaymentService\Domain\Customer\ValueObject\CustomerId;

interface CustomerAggregateRepositoryInterface
{
    public function retrieve(CustomerId $aggregateRootId): CustomerAggregate;

    public function persist(CustomerAggregate $aggregateRoot): void;
}
