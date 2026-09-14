<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\ValueObject;

use EventSauce\EventSourcing\AggregateRootId;
use Techork\PaymentService\Common\ValueObject\UuidValueObject;

/**
 * Our identifier for the case, never the provider's.
 *
 * The provider's own reference — `dp_1P…` at Stripe, an id containing `/` and `+` at Nuvei,
 * a `CaseNumber` at ConnexPay — is never a field on this aggregate or on any other. It lives in
 * the polymorphic `gateway_references` table, under the `dispute` morph type
 * (`Techork\PaymentService\Laravel\Repository\EloquentGatewayTransactionRepository::TYPE_DISPUTE`,
 * named in prose rather than linked because the Domain package may not see the Laravel one), and it
 * is that table which is read back by whoever has to address the provider about the case. The two
 * are kept apart because they have different lifetimes: a case can be re-referenced by a provider
 * (ConnexPay's second chargeback carries a new `CaseNumber` in the same family), while the
 * aggregate that owns the money and the deadline must not change identity underneath its
 * event stream.
 *
 * Also why this is a uuid of our own making rather than a hash of the provider's: the same
 * provider id can be reused across gateway accounts, so an id derived from it would collide
 * across MIDs exactly where the accounts differ.
 */
final readonly class DisputeId extends UuidValueObject implements AggregateRootId {}
