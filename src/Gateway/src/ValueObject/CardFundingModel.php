<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\ValueObject;

/**
 * Where a virtual card's money comes from.
 *
 * This is the axis the card-issuing providers actually differ on, and the one thing that used to
 * be inferred rather than stated. "ConnexPay needs a payment, Revolut and ConfermaPay do not" is
 * not a property of the vendor — it is what follows from the funding model, and reading it off
 * the presence of a transaction reference made the caller's intent unrecoverable: a reference that
 * happened to be absent looked exactly like a card that never needed one.
 *
 * Two cases and no third. Either the money was already taken from a cardholder and the card draws
 * on that one sale ({@see self::Sale}), or it draws on a pot the merchant funded ahead of time and
 * no payment is named at all ({@see self::Balance}). A provider is not fixed to one of them —
 * ConnexPay issues both, against `POST /api/v1/IssueCard` and its lodged-card sibling — which is
 * precisely why the model belongs to the command rather than to the gateway.
 */
enum CardFundingModel: string
{
    case Sale = 'sale';

    case Balance = 'balance';
}
