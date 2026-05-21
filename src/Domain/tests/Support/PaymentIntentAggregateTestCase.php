<?php

declare(strict_types=1);

namespace Techork\PaymentService\Tests\Support;

use Techork\PaymentService\Domain\PaymentIntent\Command\CreatePaymentIntentCommand;
use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentAggregate;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;
use EventSauce\EventSourcing\AggregateRootId;
use EventSauce\EventSourcing\TestUtilities\AggregateRootTestCase;

abstract class PaymentIntentAggregateTestCase extends AggregateRootTestCase
{
    protected function newAggregateRootId(): AggregateRootId
    {
        return PaymentIntentId::generate();
    }

    protected function aggregateRootClassName(): string
    {
        return PaymentIntentAggregate::class;
    }

    protected function handle(CreatePaymentIntentCommand $command): void
    {
        throw new \LogicException(
            'PaymentIntentAggregate::create now requires a CreatePort. '
            . 'Call PaymentIntentAggregate::create(...) directly in the test instead of when($command).'
        );
    }
}
