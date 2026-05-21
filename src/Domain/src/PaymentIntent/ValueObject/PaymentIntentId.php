<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\ValueObject;

use EventSauce\EventSourcing\AggregateRootId;
use Techork\PaymentService\Common\ValueObject\UuidValueObject;

final readonly class PaymentIntentId extends UuidValueObject implements AggregateRootId {}
