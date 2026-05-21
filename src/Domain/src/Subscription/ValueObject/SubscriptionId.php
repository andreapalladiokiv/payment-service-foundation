<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Subscription\ValueObject;

use EventSauce\EventSourcing\AggregateRootId;
use Techork\PaymentService\Common\ValueObject\UuidValueObject;

final readonly class SubscriptionId extends UuidValueObject implements AggregateRootId {}
