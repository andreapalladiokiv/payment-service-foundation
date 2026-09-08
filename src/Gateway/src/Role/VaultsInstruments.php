<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Role;

use Techork\PaymentService\Gateway\Command\VaultCommand;
use Techork\PaymentService\Gateway\Contract\RegistrationResult;
use Techork\PaymentService\Gateway\Exception\UnsupportedByGateway;

/**
 * Giving an instrument to the provider to keep.
 *
 * The asymmetry between the two is deliberate and predates this interface. A token is a one-use
 * handle that expires, so a collection of them belonging to a person would fill with dead
 * entries — which is why `Domain\Customer\CustomerAggregate` holds payment methods and refuses
 * tokens. Tokenizing is therefore an operation on an instrument and nothing else, while
 * registering a payment method is an operation on somebody's instrument.
 *
 * That aggregate is named in prose and not as a `{@see}`, deliberately: this package depends on
 * `Common` alone and cannot load a domain type. The prose outran the class once — it described
 * `CustomerAggregate` while no such class existed — so the name is worth keeping only as long as
 * the class is.
 *
 * **"Somebody's" is now enforced rather than described.** {@see registerPaymentMethod()} refuses a
 * {@see \Techork\PaymentService\Gateway\Command\VaultCommand} that names no customer at the
 * two providers whose product requires one — Stripe will not make a PaymentMethod reusable without
 * a Customer, Nuvei cannot produce a `userPaymentOptionId` without a `userTokenId` — with
 * {@see \Techork\PaymentService\Gateway\Exception\RegistrationNeedsCustomer}. `tokenize()`
 * takes the same command and reads no customer from it, which is the asymmetry made concrete.
 *
 * At ConnexPay this operation is also where the customer is BORN: no endpoint there creates one
 * without a card, so the command carries the identity as well as the id, and the provider's guid
 * comes back on the result.
 *
 * No client in this repository calls either: both are driven from the application, which is also
 * why no domain port declares them. That absence is information rather than a gap — nothing here
 * has an aggregate whose invariants depend on a vaulted card.
 */
interface VaultsInstruments
{
    /**
     * @throws UnsupportedByGateway
     */
    public function tokenize(VaultCommand $command): RegistrationResult;

    /**
     * @throws UnsupportedByGateway
     */
    public function registerPaymentMethod(VaultCommand $command): RegistrationResult;
}
