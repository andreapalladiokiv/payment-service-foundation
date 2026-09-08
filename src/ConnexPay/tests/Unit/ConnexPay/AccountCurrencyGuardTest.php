<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\ConnexPay\ConnexPaySettings;

/**
 * ConnexPay's API carries no currency field — it bills `Amount` in the currency
 * the merchant account is provisioned with. Without a guard a Money in another
 * currency is silently rebranded, so JPY 5000 (about $32) would be charged as
 * $5,000 on a USD account.
 *
 * The guard lives on {@see ConnexPaySettings} because that is where the account currency lives:
 * it used to be a trait method reading a parameter bag, which is why the check had to be
 * exercised through a stub request that validated nothing.
 */
function accountCurrencySettings(?string $accountCurrency = null): ConnexPaySettings
{
    return new ConnexPaySettings(accountCurrency: $accountCurrency ?? '');
}

it('defaults the account currency to USD when unset, empty or blank', function (?string $configured) {
    expect(accountCurrencySettings($configured)->acquiringCurrency())->toBe('USD');
})->with([null, '', '   ']);

it('reads the configured account currency, case-insensitively', function () {
    expect(accountCurrencySettings('cad')->acquiringCurrency())->toBe('CAD')
        ->and(accountCurrencySettings(' eur ')->acquiringCurrency())->toBe('EUR');
});

it('formats an amount that matches the account currency', function () {
    expect(accountCurrencySettings()->formatAmount(new Money(1050, new Currency('USD'))))->toBe('10.50')
        ->and(accountCurrencySettings('GBP')->formatAmount(new Money(1050, new Currency('GBP'))))->toBe('10.50');
});

it('refuses an amount whose currency is not the account currency', function (string $account, string $amount) {
    expect(fn () => accountCurrencySettings($account)->formatAmount(new Money(5000, new Currency($amount))))
        ->toThrow(InvalidArgumentException::class, "provisioned in {$account} but the amount is {$amount}");
})->with([
    ['USD', 'JPY'],
    ['USD', 'CAD'],
    ['CAD', 'USD'],
    ['EUR', 'GBP'],
]);

it('refuses to be configured with a currency ConnexPay does not acquire in', function (string $code) {
    // Issuing supports ~30 currencies; acceptance supports four. Matching an
    // amount against an issuing-only currency would reinstate the mis-billing
    // the guard exists to prevent, so the misconfiguration must fail here.
    expect(fn () => accountCurrencySettings($code)->acquiringCurrency())
        ->toThrow(InvalidArgumentException::class, 'does not acquire in');
})->with(['JPY', 'CHF', 'AUD', 'XXX', 'US']);

it('rejects a mismatched amount even when both currencies share a scale', function () {
    // So the guard cannot be mistaken for a formatting concern: USD and CAD both
    // have two minor digits, yet the amount must still be refused.
    expect(fn () => accountCurrencySettings('USD')->formatAmount(new Money(1050, new Currency('CAD'))))
        ->toThrow(InvalidArgumentException::class);
});
