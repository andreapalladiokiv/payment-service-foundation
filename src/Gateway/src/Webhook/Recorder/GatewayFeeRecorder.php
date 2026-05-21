<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Webhook\Recorder;

use DateTimeImmutable;
use Money\Money;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Records the processor / acquirer fee paid for a PaymentIntent or Refund —
 * arrives out-of-band from gateway-specific async signals (Stripe
 * `charge.updated` / `charge.refund.updated`, Nuvei DMN `feeAmount`,
 * ConnexPay daily settlement file).
 *
 * `$internalId` is our internal aggregate id (UUID string), already
 * resolved by the caller via {@see TransactionIdResolver}. Implementations
 * load the aggregate, dispatch the corresponding `recordFee` command,
 * and persist. {@see RecorderOutcome::NotFound} signals the caller to
 * delay-and-retry — the aggregate hasn't been observed yet.
 */
interface GatewayFeeRecorder
{
    public function onPaymentIntentFee(GatewayId $gatewayId, string $paymentIntentId, Money $fee, DateTimeImmutable $observedAt): RecorderOutcome;

    public function onRefundFee(GatewayId $gatewayId, string $refundId, Money $fee, DateTimeImmutable $observedAt): RecorderOutcome;

    public function onVirtualCardFee(GatewayId $gatewayId, string $virtualCardId, Money $fee, DateTimeImmutable $observedAt): RecorderOutcome;
}
