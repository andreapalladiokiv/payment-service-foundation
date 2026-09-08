<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Customer\ValueObject;

use EventSauce\EventSourcing\AggregateRootId;
use Techork\PaymentService\Common\Contract\CustomerIdentifier;
use Techork\PaymentService\Common\ValueObject\UuidValueObject;

/**
 * WHICH customer, minted by us. Its neighbour
 * {@see \Techork\PaymentService\Common\ValueObject\CustomerIdentity} is WHO they are.
 *
 * Here rather than in `Common`, next to its aggregate, exactly where `PaymentIntentId`,
 * `CheckoutId`, `SubscriptionId` and `RefundId` are — and it has to be: an EventSauce aggregate
 * root id implements `AggregateRootId`, and eventsauce is only a dev dependency of `Common`,
 * which `Gateway` and all five provider packages require at runtime.
 *
 * It is **ours**. Not derived from any attribute of the customer and not adopted from a provider
 * or a merchant, which is the whole reason an email can be a field on the identity instead of
 * being the identity — the state this aggregate exists to replace, where Nuvei knew a person by
 * the address they last paid from.
 *
 * `Gateway` and the provider packages name the customer through {@see CustomerIdentifier}, which
 * this satisfies with methods `UuidValueObject` already provides. They can accept and stringify
 * one; they cannot construct one, and nothing out there needs to.
 */
final readonly class CustomerId extends UuidValueObject implements AggregateRootId, CustomerIdentifier {}
