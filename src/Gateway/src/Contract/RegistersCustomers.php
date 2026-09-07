<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Contract;

use Omnipay\Common\Message\AbstractRequest;

/**
 * A gateway whose customer object can be created on its own, from an identity and nothing else.
 *
 * Narrow for the same reason {@see ResolvesGatewayCustomers} is, but read the boundary carefully:
 * it is *creatable independently*, not *exists*. Paynet and Revolut have no customer at all.
 * **ConnexPay does have one** — `/api/v1/verify` returns `card.customer.guid`, and
 * `ConnexPay\CreatePaymentMethodRequest` is what brings it into existence — and it is still
 * excluded here, because every ConnexPay endpoint that creates a customer requires a card. There
 * is no call to make with an identity and no instrument, so asking is a wiring error rather than a
 * decline; the customer is registered by registering their payment method, which takes the
 * identity for that reason.
 *
 * This used to say ConnexPay had no customer object, on the grounds that its `CustomerID` is a
 * searchable field on a transaction. That field is real and is a different thing: it carries *our*
 * id for reporting, alongside a `Customer` object that the provider owns and keys itself. Reading
 * the first as evidence against the second is what left an address-derived provider customer alive
 * inside a registration.
 *
 * Stripe and Nuvei are the two that qualify, and they differ in what attaching means. A Stripe PaymentMethod is
 * unattached until it is attached to a Customer, and unattached means single-use. A Nuvei
 * `userPaymentOptionId` exists only under the `userTokenId` it was stored against, and the docs do
 * not promise it survives a change of token. Both make a customer created *after* the fact useless
 * for an instrument attached earlier, which is why creating one is an operation of its own rather
 * than a side effect of saving a card.
 */
interface RegistersCustomers
{
    /**
     * Expects `customerId` (ours) and `customerIdentity`; returns the provider's own id as the
     * transaction reference.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function createCustomer(array $parameters = []): AbstractRequest;
}
