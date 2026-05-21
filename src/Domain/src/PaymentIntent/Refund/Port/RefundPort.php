<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Refund\Port;

use Techork\PaymentService\Domain\PaymentIntent\Port\GatewayDeclinedException;
use Techork\PaymentService\Domain\PaymentIntent\Refund\Port\Request\RefundRequest;

interface RefundPort
{
    /**
     * @throws GatewayDeclinedException
     */
    public function refund(RefundRequest $request): void;
}
