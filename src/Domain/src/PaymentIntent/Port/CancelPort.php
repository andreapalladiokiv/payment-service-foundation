<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Port;

use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CancelRequest;

interface CancelPort
{
    /**
     * @throws GatewayDeclinedException
     */
    public function cancel(CancelRequest $request): void;
}
