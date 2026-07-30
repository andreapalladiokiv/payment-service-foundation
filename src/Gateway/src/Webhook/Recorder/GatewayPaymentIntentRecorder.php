<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Webhook\Recorder;

use Money\Money;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Records that the gateway has a PaymentIntent we may not have — creates the
 * local aggregate if we haven't seen it yet, otherwise reports Skipped.
 * Idempotent on (gateway_id, paymentIntentReference).
 *
 * The counterpart to {@see GatewayPaymentMethodRecorder}, and the reason every
 * other PaymentIntent recorder can insist the intent already exists. Those all
 * take a resolved local id and report {@see RecorderOutcome::NotFound} when the
 * reference resolves to nothing, which the handlers turn into a retry — correct
 * for a webhook that overtook the one before it, useless for the first webhook
 * about an intent this side never created. Something has to be allowed to
 * create, or the ordering retries have nothing to converge on.
 *
 * Note what that admits: an intent opened on the gateway account by anything at
 * all — another integration, the gateway's own dashboard, a second environment
 * sharing credentials — becomes a local intent. That is the intended trade, but
 * it is a real one, so applications that do not want it bind the no-op.
 */
interface GatewayPaymentIntentRecorder
{
    /**
     * @param  string|null  $paymentMethodReference  the instrument the gateway
     *   names on the intent, where it names one at all. Frequently absent:
     *   Stripe, for one, attaches the payment method at confirmation rather than
     *   at creation, so the first webhook about an intent carries none.
     * @param  string|null  $description  free text from the gateway, for humans
     * @param  string|null  $merchantDescriptor  what the cardholder's statement
     *   is expected to show
     */
    public function onPaymentIntentRecord(
        GatewayId $gatewayId,
        string $paymentIntentReference,
        Money $amount,
        ?string $paymentMethodReference,
        ?string $description,
        ?string $merchantDescriptor,
    ): RecorderOutcome;
}
