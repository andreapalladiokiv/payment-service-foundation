<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Contract;

use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Which id a gateway knows one of our customers under.
 *
 * The same kind of thing as {@see VirtualCardReferenceRepository} and
 * {@see GatewayTransactionRepository}, named and shaped to match: our id in, that gateway's
 * reference out. It replaces {@see CustomerRepository}, which is named for the *thing* while
 * every sibling is named for what it stores, and keyed by an **instrument** while every sibling
 * is keyed by whatever the reference belongs to. That key is why a raw card can never resolve a
 * customer today and why an expiring `Token` can.
 *
 * The customer id arrives as a `string` for the reason
 * {@see Webhook\Contract\TransactionIdResolver} gives: it keeps this package free of domain
 * value objects, and the caller wraps back into a typed id at the domain boundary. This is also
 * the only place in the tree that speaks of a `customer_reference` at all — a provider's own id
 * for a person is not a fact the aggregate or `Common` is allowed to learn, and
 * `PackageHierarchyTest` pins that.
 *
 * **A miss is an ordinary answer, not a failure.** ConnexPay is the case that makes this
 * load-bearing: it has a customer object — `/api/v1/verify` builds one and hands it back as
 * `card.customer.guid` — but every endpoint that creates one requires a card, so there is no
 * identity-only call to make and no reference to be had until a payment method is registered.
 * A caller reading null as an error, or a contract shaped as "give me the reference or throw",
 * breaks that gateway on its first call. Null means "not yet", the caller carries on without a
 * customer on the request, and {@see saveReference()} is how whatever a registration handed
 * back gets remembered.
 *
 * One reference per gateway per customer is enforced by the table, not here — re-pointing a
 * gateway at a different reference orphans whatever the old one owned, and a UNIQUE constraint
 * can refuse that without the domain learning that providers exist.
 *
 * **Erasure does not reach these rows, by design rather than by omission.** Forgetting a
 * customer erases the identity in its own stream; a `cus_...` is not personal data, and dropping
 * it would orphan every payment method and every payment that names it — including payments that
 * must stay auditable. The note belongs here and not on the aggregate, because the aggregate
 * holds no such links at all: it never learns that providers exist, so it cannot be the place
 * that says what happens to their references.
 */
interface GatewayCustomerRepository
{
    public function find(GatewayId $gatewayId, string $customerId): ?string;

    public function saveReference(GatewayId $gatewayId, string $customerId, string $reference): void;
}
