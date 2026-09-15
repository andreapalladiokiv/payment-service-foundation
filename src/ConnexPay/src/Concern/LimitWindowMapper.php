<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay\Concern;

use Techork\PaymentService\Gateway\ValueObject\CardLimitWindow;

/**
 * The domain's limit window as ConnexPay's `LimitWindow`.
 *
 * Total, with no fallback and nothing to refuse: {@see CardLimitWindow} is deliberately the set of
 * periods both supported issuers honour natively, so every case here has an exact counterpart and
 * no card can be handed a window that spends a different amount than the one asked for.
 *
 * On spelling: the vendor's OpenAPI carries no `enum`, its reference page says "Day, Week, Month,
 * Lifetime", its guide says "Daily / Weekly / Monthly / Lifetime", and every worked example —
 * request and response alike — is upper case (`"LimitWindow": "MONTH"`, `"limitWindow": "DAY"`).
 * The examples win, being the only form observed on the wire; `WEEK` is the one value no example
 * shows, so it rests on the prose alone.
 */
final readonly class LimitWindowMapper
{
    public static function fromWindow(CardLimitWindow $window): string
    {
        return match ($window) {
            CardLimitWindow::Day => 'DAY',
            CardLimitWindow::Week => 'WEEK',
            CardLimitWindow::Month => 'MONTH',
            CardLimitWindow::Lifetime => 'LIFETIME',
        };
    }
}
