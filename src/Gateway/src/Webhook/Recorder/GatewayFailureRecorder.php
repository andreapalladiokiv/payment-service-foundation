<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Webhook\Recorder;


/**
 * Records that the gateway failed/declined a PaymentIntent.
 */
interface GatewayFailureRecorder
{
    public function onGatewayFailure(string $paymentIntentId, string $reason): RecorderOutcome;
}
