<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Webhook\Recorder;

use Override;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Default {@see GatewayPaymentMethodRecorder}: silently skips every record.
 *
 * PaymentMethod storage is application-defined — the bridge binds this no-op
 * so inbound `payment_method.attached`-style webhooks resolve and ack without
 * touching local state. Applications with local PaymentMethod storage override
 * the binding in their own service provider.
 */
final readonly class NoOpGatewayPaymentMethodRecorder implements GatewayPaymentMethodRecorder
{
    #[Override]
    public function onPaymentMethodRecord(
        GatewayId $gatewayId,
        string $customerReference,
        string $paymentMethodReference,
        CreditCard $creditCard,
        BillingAddress $billingAddress,
    ): RecorderOutcome {
        return RecorderOutcome::Skipped;
    }
}
