<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Webhook\Recorder;

use Money\Money;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Records that the gateway reported a successful PaymentIntent transition.
 * Implementation decides whether to apply a charge (from Pending) or a capture
 * (from Authorized); completes any pending 3DS challenge first.
 */
interface GatewaySuccessRecorder
{
    public function onGatewaySuccess(
        GatewayId $gatewayId,
        string $paymentIntentId,
        string $gatewayReference,
        Money $amount,
    ): RecorderOutcome;
}
