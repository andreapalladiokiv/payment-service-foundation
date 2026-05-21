<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Webhook\Recorder;


/**
 * Records that the gateway cancelled / voided a PaymentIntent.
 */
interface GatewayCancellationRecorder
{
    public function onGatewayCancellation(string $paymentIntentId): RecorderOutcome;
}
