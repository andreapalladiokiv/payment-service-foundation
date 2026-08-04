<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject;

/**
 * How a payment was initiated, per the card networks' Stored Credential
 * Framework.
 *
 * Lives in Common rather than with the payment intent because it is a fact about
 * the transaction as the networks define it, like {@see ThreeDS\ECICode} or
 * {@see CardBrand}, and because the adapters have to name it: the gateway
 * package cannot see the domain one (its composer.json requires only
 * payment-service-common), so an indicator that never crosses that line never
 * reaches an acquirer.
 *
 * The cardholder-facing controls — fraud screening and a 3DS step-up — apply only
 * to a {@see self::CardholderInitiated} (CIT) transaction. A merchant-initiated
 * one has no cardholder present, so it must never be parked on a step-up: there
 * would be nobody to answer the ACS.
 *
 * That is not the same as never authenticating it. EMV 3DS requestor-initiated
 * (3RI) authentication exists to obtain a cryptogram for precisely this case,
 * with no cardholder involved, and a MIT carrying one is ordinary rather than
 * contradictory. What every MIT must carry is the matching indicator — recurring
 * versus unscheduled card-on-file — which each adapter maps to its own
 * vocabulary.
 *
 * Note what this does NOT say: {@see self::CardholderInitiated} does not mean
 * "the first payment of a stored-credential series". A one-off checkout is
 * cardholder-initiated too, and an acquirer that wants the initiating
 * transaction of a series flagged needs a distinction this enum does not draw.
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
