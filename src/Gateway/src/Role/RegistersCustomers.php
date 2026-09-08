<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Role;

use Techork\PaymentService\Gateway\Command\RegisterCustomerCommand;
use Techork\PaymentService\Gateway\Contract\RegistrationResult;
use Techork\PaymentService\Gateway\Exception\UnsupportedByGateway;

/**
 * Holding a customer at the provider, without an instrument in hand.
 *
 * Read the boundary carefully, because it is not "has a customer": it is **creatable from an
 * identity alone**. Paynet and Revolut have no customer object at all. ConnexPay *does* have one
 * — `/api/v1/verify` builds it out of what it is given and hands it back as
 * `card.customer.guid` — and it still refuses this, because every ConnexPay route that can
 * create a customer takes a card. There is no call to make with an identity and no instrument,
 * so asking is a wiring error rather than a decline; on that gateway the customer comes into
 * existence by registering their payment method, which is why
 * {@see VaultsInstruments::registerPaymentMethod()} carries the identity too.
 *
 * This distinction used to be recorded the other way round — that ConnexPay had no customer
 * object, on the grounds that its `CustomerID` is a searchable field on a transaction. That
 * field is real and is a different thing: it carries *our* id for reporting, alongside a
 * `Customer` object the provider owns and keys itself. Reading the first as evidence against the
 * second is what left an address-derived provider customer alive inside a registration, in the
 * one place the write-up had declared there was nothing to look at. The refusal was right; the
 * reason was wrong, and the reason is what a reader acts on.
 *
 * Stripe and Nuvei are the two that qualify, and they differ in what attaching means. A Stripe
 * PaymentMethod is unattached until it is attached to a Customer, and unattached means
 * single-use. A Nuvei `userPaymentOptionId` exists only under the `userTokenId` it was stored
 * against, and the docs do not promise it survives a change of token. Both make a customer
 * created *after* the fact useless for an instrument attached earlier, which is the second
 * reason this is an operation and not a side effect.
 *
 * A refusal here is {@see UnsupportedByGateway}-marked so the stack rethrows it rather than
 * folding it into a failed result: turning it into a decline would read as a provider refusing a
 * customer it was never told about.
 */
interface RegistersCustomers
{
    /**
     * @throws UnsupportedByGateway when the provider cannot make a customer without a card
     */
    public function registerCustomer(RegisterCustomerCommand $command): RegistrationResult;
}
