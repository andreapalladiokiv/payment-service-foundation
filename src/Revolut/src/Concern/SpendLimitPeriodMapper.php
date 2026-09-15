<?php

declare(strict_types=1);

namespace Techork\PaymentService\Revolut\Concern;

use Techork\PaymentService\Gateway\ValueObject\CardLimitWindow;
use Techork\PaymentService\Revolut\CardSettings;

/**
 * The domain's limit window as the key Revolut's `spending_limits` object is written under.
 *
 * Three of the four map to the same word and one does not, which is the whole reason this is a
 * mapper and not a `->value`. Revolut spells "never refills" as `all_time`; the domain spells it
 * `lifetime`, following ConnexPay, because naming a shared concept after whichever vendor was
 * integrated first is how a vocabulary becomes one vendor's dictionary.
 *
 * Revolut's own `single`, `quarter` and `year` have no domain case and are not reachable from a
 * command. They stay reachable from {@see CardSettings::$spendLimitPeriod},
 * which is where they have always lived and which is still what a card naming no window gets.
 */
final readonly class SpendLimitPeriodMapper
{
    public static function fromWindow(CardLimitWindow $window): string
    {
        return match ($window) {
            CardLimitWindow::Day => 'day',
            CardLimitWindow::Week => 'week',
            CardLimitWindow::Month => 'month',
            CardLimitWindow::Lifetime => 'all_time',
        };
    }
}
