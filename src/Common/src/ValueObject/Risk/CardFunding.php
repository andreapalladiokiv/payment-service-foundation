<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject\Risk;

/**
 * Funding type of a card, as reported by a BIN-intelligence provider
 * (e.g. Neutrino `bin-lookup`) or the acquirer's tokenization response.
 * Feeds fraud rules that treat prepaid / commercial cards differently.
 *
 * {@see Unknown} when the provider could not classify the BIN.
 */
enum CardFunding: string
{
    case Credit = 'credit';
    case Debit = 'debit';
    case Prepaid = 'prepaid';
    case Charge = 'charge';
    case Unknown = 'unknown';
}
