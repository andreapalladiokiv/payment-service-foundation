<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Contract;

use Techork\PaymentService\Common\ValueObject\CustomerIdentity;

/**
 * Who a customer is, for the packages that have to tell a provider.
 *
 * Stripe and Nuvei both create a provider-side customer and both need a name, an email and a
 * phone to do it. They cannot read the customer aggregate: a provider package depends on
 * `Common` + `Gateway` and can load neither `Domain` nor the aggregate's id type. So this
 * states what they need and the host answers it — against the aggregate or against a
 * projection, its choice.
 *
 * The same shape and the same reason as {@see Webhook\Contract\TransactionIdResolver}: the id
 * crosses as a `string`, and {@see CustomerIdentity} is in `Common` because it is data rather
 * than an id, exactly as `BillingAddress` is.
 *
 * Null means the host has no such customer. What that costs is the caller's to decide: today
 * both adapters would build a provider-side customer out of whatever address rode along with
 * the payment, and a null here is how they learn to stop.
 */
interface CustomerIdentitySource
{
    public function find(string $customerId): ?CustomerIdentity;
}
