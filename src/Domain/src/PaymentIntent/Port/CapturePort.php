<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Port;

use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CaptureRequest;

interface CapturePort
{
    /**
     * @throws GatewayDeclinedException
     */
    public function capture(CaptureRequest $request): void;
}
