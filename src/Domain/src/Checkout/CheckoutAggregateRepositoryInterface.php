<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Checkout;

use Techork\PaymentService\Domain\Checkout\ValueObject\CheckoutId;

interface CheckoutAggregateRepositoryInterface
{
    public function retrieve(CheckoutId $aggregateRootId): CheckoutAggregate;

    public function persist(CheckoutAggregate $aggregateRoot): void;
}
