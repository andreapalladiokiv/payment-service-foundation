<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\ValueObject;


/**
 * How often a card's spend limit refills.
 *
 * A sale-funded card has no window: the sale bounds it once and for good. A balance-funded one
 * draws on a pot that outlives it, so "limit" says nothing until you also say "per what" — which
 * is why every balance issuer asks for this.
 *
 * **The four periods here are the ones both issuers honour natively, and the list stops there on
 * purpose.** Revolut's `spending_limits` additionally accepts `single`, `quarter` and `year`;
 * ConnexPay's lodged `LimitWindow` accepts `DAY`, `WEEK`, `MONTH` and `LIFETIME` and nothing else.
 * Taking Revolut's seven wholesale would have made the shared vocabulary one vendor's dictionary
 * and left ConnexPay's mapper choosing a "closest fit" — and a mis-fitted window is not a
 * mislabelling, it changes how much money the card may spend: `quarter` rounded to `MONTH` hands
 * the card its full limit three times over the same span. The same reasoning
 * {@see CardSpendCategory} gives for not adding categories speculatively, applied to money
 * directly rather than to merchant restrictions.
 *
 * Revolut's extra three are not lost; they are reachable where they have always lived, in the
 * deployment's `Revolut\CardSettings::$spendLimitPeriod`, which is also what a card naming no
 * window falls back to. Named in prose rather than linked: a `{@see}` here would import one
 * gateway's class into the shared vocabulary.
 *
 * ConfermaPay is deliberately not modelled here at all: its window is a start/end date pair, a
 * shape no period enum can carry, and it keeps its own two date fields rather than being flattened
 * into a period that would lose the dates.
 */
enum CardLimitWindow: string
{
    case Day = 'day';

    case Week = 'week';

    case Month = 'month';

    /** No refill: the limit is a lifetime total for the card. */
    case Lifetime = 'lifetime';
}
