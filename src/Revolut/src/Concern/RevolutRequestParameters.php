<?php

declare(strict_types=1);

namespace Techork\PaymentService\Revolut\Concern;

use Money\Currencies\ISOCurrencies;
use Money\Formatter\DecimalMoneyFormatter;
use Money\Money;
use Techork\PaymentService\Gateway\ValueObject\CardLimitWindow;
use Techork\PaymentService\Revolut\CardSettings;

/**
 * Formatting a spend limit the way Revolut wants it.
 *
 * All that is left of a trait that used to be nineteen bag-backed accessors. Every one of those
 * values now arrives through a constructor — the command's, or {@see CardSettings} —
 * so what remains is the one piece of behaviour two requests genuinely share.
 */
trait RevolutRequestParameters
{
    protected function formatMoney(Money $money): string
    {
        return new DecimalMoneyFormatter(new ISOCurrencies)->format($money);
    }

    /**
     * @return array<string, array{amount: float, currency: string}>
     */
    protected function buildSpendingLimits(Money $money, string $period): array
    {
        return [
            $period => [
                'amount' => (float) $this->formatMoney($money),
                'currency' => $money->getCurrency()->getCode(),
            ],
        ];
    }

    /**
     * The period the limit is keyed by: what this card asked for, or what the deployment configured.
     *
     * The order is the whole point. Before the funding model existed the period was a deployment
     * setting and nothing else, so every card issued through a gateway shared one — and the setting
     * cannot simply be replaced by the command, because then a card issued while it said `single`
     * would be re-periodised the moment a later request named something different. A per-card
     * window overrides; silence defers.
     */
    protected function resolveSpendLimitPeriod(?CardLimitWindow $window, string $configured): string
    {
        return $window === null ? $configured : SpendLimitPeriodMapper::fromWindow($window);
    }
}
