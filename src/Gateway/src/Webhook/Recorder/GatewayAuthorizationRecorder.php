<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Webhook\Recorder;

use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Records that the gateway authorized a PaymentIntent (pre-capture hold).
 */
interface GatewayAuthorizationRecorder
{
    public function onGatewayAuthorization(
        GatewayId $gatewayId,
        string $paymentIntentId,
        string $gatewayReference,
    ): RecorderOutcome;
}
