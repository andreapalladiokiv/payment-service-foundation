<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Webhook\Recorder;

use Money\Money;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Records that the gateway processed a refund. Implementation transitions the
 * refund to Processed, creating the aggregate first if this is the first time
 * we see it (e.g. dashboard-initiated refunds).
 */
interface RefundProcessingRecorder
{
    public function onRefundProcessed(
        GatewayId $gatewayId,
        string $paymentIntentId,
        string $refundReference,
        Money $amount,
    ): RecorderOutcome;
}
