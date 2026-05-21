<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Checkout\ValueObject;

use EventSauce\EventSourcing\AggregateRootId;
use Techork\PaymentService\Common\ValueObject\UuidValueObject;

final readonly class CheckoutId extends UuidValueObject implements AggregateRootId {}
