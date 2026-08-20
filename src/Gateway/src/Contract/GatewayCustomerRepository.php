<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Contract;

use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Which id a gateway knows one of our customers under.
 *
 * The same kind of thing as {@see VirtualCardReferenceRepository} and
 * {@see GatewayTransactionRepository}, named and shaped to match: our id in, that gateway's
 * reference out. It replaces the deleted `CustomerRepository`, which was named for the *thing*
 * while every sibling is named for what it stores, and keyed by an **instrument** while every
 * sibling is keyed by what the reference belongs to. That key is why a raw card could never
 * resolve a customer and why an expiring `Token` could.
 *
 * The customer id arrives as a `string` for the reason {@see Webhook\Contract\TransactionIdResolver}
 * gives: it keeps this package free of domain value objects, and the caller wraps back into a
 * typed id at the domain boundary.
 *
 * One reference per gateway per customer is enforced by the table, not here — re-pointing a
 * gateway at a different reference orphans whatever the old one owned, and a UNIQUE constraint
 * can refuse that without the domain learning that providers exist.
 *
 * **Erasure does not reach these rows, by design rather than by omission.** Forgetting a customer
 * erases the identity in its own stream; a `cus_...` is not personal data, and dropping it would
 * orphan every payment method and every payment that names it — including payments that must stay
 * auditable. The note belongs here and not on the aggregate, because the aggregate holds no such
 * links at all: it never learns that providers exist, so it cannot be the place that says what
 * happens to their references. See F7 in `docs/customer-domain-plan`.
 */
interface GatewayCustomerRepository
{
    public function find(GatewayId $gatewayId, string $customerId): ?string;

    public function saveReference(GatewayId $gatewayId, string $customerId, string $reference): void;
}
