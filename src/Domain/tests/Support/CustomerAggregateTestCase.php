<?php

declare(strict_types=1);

namespace Techork\PaymentService\Tests\Support;

use EventSauce\EventSourcing\AggregateRootId;
use EventSauce\EventSourcing\TestUtilities\AggregateRootTestCase;
use Techork\PaymentService\Domain\Customer\Command\RegisterCustomerCommand;
use Techork\PaymentService\Domain\Customer\CustomerAggregate;
use Techork\PaymentService\Domain\Customer\ValueObject\CustomerId;

abstract class CustomerAggregateTestCase extends AggregateRootTestCase
{
    protected function newAggregateRootId(): AggregateRootId
    {
        return CustomerId::generate();
    }

    protected function aggregateRootClassName(): string
    {
        return CustomerAggregate::class;
    }

    protected function handle(RegisterCustomerCommand $arguments): void
    {
        $this->persistAggregateRoot(CustomerAggregate::register($arguments));
    }
}
