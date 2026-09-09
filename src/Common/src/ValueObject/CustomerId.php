<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject;

/**
 * WHICH customer, minted by us. Its neighbour {@see CustomerIdentity} is WHO they are, and
 * {@see Customer} is the two of them together with where the person is billed.
 *
 * It is **ours**. Not derived from any attribute of the customer and not adopted from a provider
 * or a merchant, which is the whole reason an email can be a field on the identity instead of
 * being the identity — the state this type exists to replace, where Nuvei knew a person by the
 * address they last paid from.
 *
 * In `Common` because that is where {@see Customer} is, and it can be: this used to live in
 * `Domain` next to a `Customer` aggregate, forced there because an EventSauce aggregate root id
 * implements `AggregateRootId` and eventsauce is only a dev dependency of `Common`. The customer
 * is not event-sourced in this package any more, so the constraint went with the aggregate.
 *
 * **A concrete class and not an interface, which took two goes to get right.** There was a
 * `Common\Contract\CustomerIdentifier` for a while, and its whole justification was that the only
 * implementation lived in `Domain`: an interface was the one way `Gateway` and the provider
 * packages could NAME a customer's id without being able to load a domain type. With the id here,
 * that argument is gone, and what was left was a second name for one thing — every boundary
 * declaring the interface while every caller passed the class, plus a normalizer in the event
 * stream whose only job was to turn an uninstantiable interface back into this.
 *
 * What the interface was also *claimed* to give — that a package could hold an id and not mint one
 * — it stopped giving the moment the class became loadable everywhere. The thing that actually
 * prevents a gateway call inventing a payer is that {@see Customer} has no partial form: a call
 * with nobody to name passes no customer, rather than assembling one out of the billing address
 * that rode along. That is an invariant a type can hold; "you may refer to this but not make one"
 * was not.
 *
 * An application whose customers are keyed by something other than a UUID of ours — a
 * merchant-supplied code, an account id it already had — maps it to one of these at its own edge.
 * That is a narrowing, and a deliberate one: it is the same string an id had to reduce to for
 * storage anyway, and one shape means the event stream, the reference map and every provider
 * mapper agree about what a customer id is.
 */
final readonly class CustomerId extends UuidValueObject {}
