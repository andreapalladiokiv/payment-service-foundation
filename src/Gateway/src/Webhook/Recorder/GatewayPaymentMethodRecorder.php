<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Webhook\Recorder;

use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Records that the gateway has a PaymentMethod for this customer — creates the
 * local aggregate if we haven't seen it yet, otherwise reports Skipped.
 * Idempotent on (gateway_id, paymentMethodReference).
 */
interface GatewayPaymentMethodRecorder
{
    public function onPaymentMethodRecord(
        GatewayId $gatewayId,
        string $customerReference,
        string $paymentMethodReference,
        CreditCard $creditCard,
        BillingAddress $billingAddress,
    ): RecorderOutcome;
}
