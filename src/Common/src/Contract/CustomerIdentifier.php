<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\Contract;

use Stringable;

/**
 * WHICH customer — the identity of one, not the facts about them.
 *
 * Its neighbour is {@see \Techork\PaymentService\Common\ValueObject\CustomerIdentity}, which is
 * the other half: WHO the customer is, being their name, email and phone, every field `#[Pii]`.
 * One says which record this is and never changes; the other says what the record holds and can
 * be corrected, erased, or absent. Reading either name as the other is the mistake this line
 * exists to prevent.
 *
 * Declared here and implemented in `Domain` by
 * {@see \Techork\PaymentService\Domain\Customer\ValueObject\CustomerId}, which is the shape
 * `Common` already uses for a type both sides have to speak: `CodedError` and `PaymentInstrument`
 * are interfaces here with their concrete cases elsewhere, and `Domain` classes implement them
 * already. It is what lets `Gateway` and the provider packages **name** the customer's id without
 * being able to load an aggregate's: `CustomerId` implements EventSauce's `AggregateRootId`, and
 * eventsauce is a dev dependency of `Common` — so the concrete class cannot live here without
 * dragging eventsauce into every package that requires `Common` at runtime.
 *
 * **There is no factory and no `fromString()`, deliberately.** A package outside `Domain` can
 * accept one of these and stringify it; it cannot mint one. Nothing needs to: a caller reaching a
 * gateway already holds the customer it is acting for, and a row read back out of the reference
 * map yields a *provider's* id for a person, not one of ours.
 *
 * The alternatives were measured rather than assumed. A `Common` base class subclassed in
 * `Domain` fails on `UuidValueObject::equals()`, which compares `static::class` — a parent and a
 * child standing for the same customer would report themselves unequal, silently. A second
 * `Gateway`-owned id type converted at the boundary gives one identity two names and a place to
 * get the conversion wrong. And a plain `string` gives up the type at exactly the boundary where
 * a wrong value is least visible.
 */
interface CustomerIdentifier extends Stringable
{
    public function toString(): string;
}
