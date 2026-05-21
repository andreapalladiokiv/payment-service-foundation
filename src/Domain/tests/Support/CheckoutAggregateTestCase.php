<?php

declare(strict_types=1);

namespace Techork\PaymentService\Tests\Support;

use Techork\PaymentService\Domain\Checkout\CheckoutAggregate;
use Techork\PaymentService\Domain\Checkout\Command\CreateCheckoutCommand;
use Techork\PaymentService\Domain\Checkout\ValueObject\CheckoutId;
use EventSauce\EventSourcing\AggregateRootId;
use EventSauce\EventSourcing\TestUtilities\AggregateRootTestCase;

abstract class CheckoutAggregateTestCase extends AggregateRootTestCase
{
    protected function newAggregateRootId(): AggregateRootId
    {
        return CheckoutId::generate();
    }

    protected function aggregateRootClassName(): string
    {
        return CheckoutAggregate::class;
    }

    protected function handle(CreateCheckoutCommand $command): void
    {
        $this->persistAggregateRoot(CheckoutAggregate::create($command));
    }
}
