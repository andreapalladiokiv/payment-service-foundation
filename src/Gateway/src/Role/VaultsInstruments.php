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
 * entries — which is why `CustomerAggregate` refuses to hold them. Tokenizing is therefore an
 * operation on an instrument and nothing else, while registering a payment method is an operation
 * on somebody's instrument.
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
