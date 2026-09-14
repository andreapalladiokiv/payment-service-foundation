<?php

declare(strict_types=1);

namespace Techork\PaymentService\Tests\Support;

use EventSauce\EventSourcing\AggregateRootId;
use EventSauce\EventSourcing\TestUtilities\AggregateRootTestCase;
use LogicException;
use Techork\PaymentService\Domain\Dispute\Command\OpenDisputeCommand;
use Techork\PaymentService\Domain\Dispute\DisputeAggregate;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeId;

abstract class DisputeAggregateTestCase extends AggregateRootTestCase
{
    protected function newAggregateRootId(): AggregateRootId
    {
        return DisputeId::generate();
    }

    protected function aggregateRootClassName(): string
    {
        return DisputeAggregate::class;
    }

    protected function handle(OpenDisputeCommand $arguments): void
    {
        throw new LogicException(
            'DisputeAggregate::open() is a static factory taking a command and returning the '
            . 'aggregate, so there is no $aggregate->method($command) for when() to call. '
            . 'Call DisputeAggregate::open(...) or the method under test directly instead.'
        );
    }
}
