<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Contract;

use Omnipay\Common\Message\AbstractRequest;

/**
 * A gateway that has a customer object of its own, which can therefore be created.
 *
 * Narrow for the same reason {@see ResolvesGatewayCustomers} is: not every provider has the
 * concept. ConnexPay's `CustomerID` is a *field on a transaction* — searchable, and nothing to
 * bring into existence — which is why every ConnexPay payment method is attached by definition and
 * why asking it to register a customer is a wiring error rather than a decline. Paynet and Revolut
 * have no customer either.
 *
 * Stripe and Nuvei do, and they differ in what attaching means. A Stripe PaymentMethod is
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
