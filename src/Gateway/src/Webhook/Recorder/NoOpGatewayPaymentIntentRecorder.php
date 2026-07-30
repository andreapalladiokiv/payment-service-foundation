<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Webhook\Recorder;

use Money\Money;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Default {@see GatewayPaymentIntentRecorder}: silently skips every record.
 *
 * The bridge binds this rather than an Eloquent implementation because creating
 * an intent from a webhook means accepting intents the application never
 * initiated — a decision only the application can make. Bound as the default so
 * that adding the handler cannot change any existing consumer's behaviour, and
 * so `payment_intent.created` acks instead of retrying for apps that want
 * nothing to do with it.
 *
 * Applications that do want the intents override the binding in their own
 * service provider.
 */
final readonly class NoOpGatewayPaymentIntentRecorder implements GatewayPaymentIntentRecorder
{
    public function onPaymentIntentRecord(
        GatewayId $gatewayId,
        string $paymentIntentReference,
        Money $amount,
        ?string $paymentMethodReference,
        ?string $description,
        ?string $merchantDescriptor,
    ): RecorderOutcome {
        return RecorderOutcome::Skipped;
    }
}
