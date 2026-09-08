<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Customer\ValueObject;

use EventSauce\EventSourcing\AggregateRootId;
use Techork\PaymentService\Common\ValueObject\UuidValueObject;

/**
 * Here rather than in `Common`, next to its aggregate, exactly where `PaymentIntentId`,
 * `CheckoutId`, `SubscriptionId` and `RefundId` are — and it has to be: an EventSauce
 * aggregate root id implements `AggregateRootId`, and `Common` does not depend on eventsauce.
 *
 * Gateway and provider packages never name this type. They take the id as a plain string, the
 * rule {@see \Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver} already
 * states: a string keeps those packages free of domain value objects, and the caller wraps it
 * back into a typed id at the domain boundary.
 */
final readonly class CustomerId extends UuidValueObject implements AggregateRootId {}
