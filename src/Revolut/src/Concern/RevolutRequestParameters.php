<?php

declare(strict_types=1);

namespace Techork\PaymentService\Revolut\Concern;

use Money\Currencies\ISOCurrencies;
use Money\Formatter\DecimalMoneyFormatter;
use Money\Money;

/**
 * Formatting a spend limit the way Revolut wants it.
 *
 * All that is left of a trait that used to be nineteen bag-backed accessors. Every one of those
 * values now arrives through a constructor — the command's, or {@see \Techork\PaymentService\Revolut\CardSettings} —
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
}
