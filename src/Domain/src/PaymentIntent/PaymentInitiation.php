<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent;

/**
 * How a payment was initiated, per the card networks' Stored Credential
 * Framework.
 *
 * The cardholder-facing controls — fraud screening and 3DS step-up — apply
 * only to a {@see self::CardholderInitiated} (CIT) transaction. A merchant-
 * initiated (MIT) one has no cardholder present to complete a challenge, so it
 * must never be forced into 3DS; instead it is submitted with the matching MIT
 * indicator (recurring vs unscheduled card-on-file), which the gateway adapters
 * derive from the sub-type.
 */
enum PaymentInitiation: string
{
    /** Cardholder-initiated (CIT): the customer is present and drove this payment. */
    case CardholderInitiated = 'cardholder_initiated';

    /** MIT, scheduled fixed-amount (e.g. a subscription renewal). */
    case MerchantRecurring = 'merchant_recurring';

    /** MIT, unscheduled card-on-file (e.g. an on-demand top-up). */
    case MerchantUnscheduled = 'merchant_unscheduled';

    public function isCardholderInitiated(): bool
    {
        return $this === self::CardholderInitiated;
    }

    public function isMerchantInitiated(): bool
    {
        return ! $this->isCardholderInitiated();
    }
}
