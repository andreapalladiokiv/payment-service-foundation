<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Port;

use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CreateRequest;

interface CreatePort
{
    /**
     * @return CreateOutcome carries the optional step-up challenge (non-null
     *                       when confirmation is awaited) and the FX-settled
     *                       convertedAmount when the gateway applied one.
     *
     * @throws GatewayDeclinedException
     */
    public function create(CreateRequest $request): CreateOutcome;
}
