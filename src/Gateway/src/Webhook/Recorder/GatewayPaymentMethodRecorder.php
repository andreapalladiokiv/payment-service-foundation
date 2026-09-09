<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Webhook\Recorder;

use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CustomerIdentity;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Records that the gateway has a PaymentMethod for this customer — creates the
 * local aggregate if we haven't seen it yet, otherwise reports Skipped.
 * Idempotent on (gateway_id, paymentMethodReference).
 *
 * `$billingAddress` and `$identity` arrive separately because the provider's billing block is
 * both at once — Stripe's `billing_details` carries a `name` and an `email` beside its `address`,
 * Nuvei's payload the same — and a
 * {@see \Techork\PaymentService\Common\ValueObject\BillingAddress} no longer holds a person.
 * The identity is passed rather than dropped because this is the only place it appears: the
 * webhook is telling us who the provider thinks owns this card. What to do with that is the
 * host's — attaching a card to a customer is an act of its own identity resolution, not something
 * a webhook can decide, so what arrives here are the makings of a
 * {@see \Techork\PaymentService\Common\ValueObject\Customer} rather than one.
 */
interface GatewayPaymentMethodRecorder
{
    public function onPaymentMethodRecord(
        GatewayId $gatewayId,
        string $customerReference,
        string $paymentMethodReference,
        CreditCard $creditCard,
        BillingAddress $billingAddress,
        CustomerIdentity $identity,
    ): RecorderOutcome;
}
