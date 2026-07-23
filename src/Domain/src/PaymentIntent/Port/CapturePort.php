<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Port;

use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CaptureRequest;

interface CapturePort
{
    /**
     * @return CaptureOutcome carries the FX-settled convertedAmount when the
     *                        gateway applied one, else null.
     *
     * @throws GatewayDeclinedException
     */
    public function capture(CaptureRequest $request): CaptureOutcome;
}
