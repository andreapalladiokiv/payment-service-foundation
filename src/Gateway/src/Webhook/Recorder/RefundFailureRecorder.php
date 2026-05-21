<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Webhook\Recorder;

use Money\Money;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Records that the gateway failed/declined a refund. Implementation transitions
 * the refund to Failed, creating the aggregate first if this is the first time
 * we see it.
 */
interface RefundFailureRecorder
{
    public function onRefundFailed(
        GatewayId $gatewayId,
        string $paymentIntentId,
        string $refundReference,
        Money $amount,
        string $reason,
    ): RecorderOutcome;
}
