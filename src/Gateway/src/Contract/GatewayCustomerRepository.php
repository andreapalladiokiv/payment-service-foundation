<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Contract;

use Techork\PaymentService\Common\ValueObject\CustomerId;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Which id a gateway knows one of our customers under.
 *
 * The same kind of thing as {@see VirtualCardReferenceRepository} and
 * {@see GatewayTransactionRepository}, named and shaped to match: our id in, that gateway's
 * reference out. It replaces the retired `CustomerRepository`, which was named for the *thing*
 * while every sibling is named for what it stores, and keyed by an **instrument** while every
 * sibling is keyed by whatever the reference belongs to. That key is why a raw card can never resolve a
 * customer today and why an expiring `Token` can.
 *
 * The customer arrives as a {@see CustomerId}. This package has no reason to make one — a caller
 * reaching a gateway already holds the customer it is acting for — and no way to get it wrong:
 * the id does not degrade to a `string` at this boundary, which is exactly the point where a
 * wrong value would be least visible. There is a type for it, so the boundary speaks it.
 *
 * The reference coming back the other way IS a bare string, and that asymmetry is the point: it
 * is the *provider's* id for a person, and this is the only place in the tree allowed to know
 * such a thing exists. `PackageHierarchyTest` pins that a `customer_reference` never appears in
 * `Domain` or `Common`.
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
 * **Erasure does not reach these rows, by design rather than by omission.** Forgetting a customer
 * erases the identity wherever the application keeps it; a `cus_...` is not personal data, and
 * dropping it would orphan every payment method and every payment that names it — including
 * payments that must stay auditable. The note belongs here because this is the only layer that
 * knows these references exist: an erasure routine reading a customer's own record will not find
 * them, and should not go looking.
 */
interface GatewayCustomerRepository
{
    public function find(GatewayId $gatewayId, CustomerId $customerId): ?string;

    public function saveReference(GatewayId $gatewayId, CustomerId $customerId, string $reference): void;
}
