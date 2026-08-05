<?php

declare(strict_types=1);

namespace Techork\PaymentService\Tests\Support;

use Techork\PaymentService\Domain\Subscription\Command\CreateSubscriptionCommand;
use Techork\PaymentService\Domain\Subscription\SubscriptionAggregate;
use Techork\PaymentService\Domain\Subscription\ValueObject\SubscriptionId;
use EventSauce\EventSourcing\AggregateRootId;
use EventSauce\EventSourcing\TestUtilities\AggregateRootTestCase;

abstract class SubscriptionAggregateTestCase extends AggregateRootTestCase
{
    protected function newAggregateRootId(): AggregateRootId
    {
        return SubscriptionId::generate();
    }

    protected function aggregateRootClassName(): string
    {
        return SubscriptionAggregate::class;
    }

    protected function handle(CreateSubscriptionCommand $arguments): void
    {
        $this->persistAggregateRoot(SubscriptionAggregate::create($arguments));
    }
}
