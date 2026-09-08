<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Role;

use Techork\PaymentService\Gateway\Command\PlacementCommand;
use Techork\PaymentService\Gateway\Contract\AuthorizationResult;
use Techork\PaymentService\Gateway\Exception\UnsupportedByGateway;

/**
 * Placing a payment, either way it can be placed.
 *
 * Two methods and one command: `CreateAdapter` is the single client and picks between them by
 * the intent's capture method. They stay separate methods because they are separately refusable —
 * Paynet charges on its hosted page and has no authorization step — and absence is expressed by
 * the refusal a method makes, not by a field a caller can get wrong.
 */
interface PlacesPayments
{
    /**
     * Reserve the money without taking it.
     *
     * @throws UnsupportedByGateway
     */
    public function authorize(PlacementCommand $command): AuthorizationResult;

    /**
     * Reserve and take in one call.
     *
     * @throws UnsupportedByGateway
     */
    public function charge(PlacementCommand $command): AuthorizationResult;
}
