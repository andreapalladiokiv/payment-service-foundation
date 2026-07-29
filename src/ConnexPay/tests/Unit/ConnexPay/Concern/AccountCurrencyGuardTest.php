<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Omnipay\Common\Message\AbstractRequest;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Techork\PaymentService\ConnexPay\Concern\ConnexPayRequestParameters;

/**
 * ConnexPay's API carries no currency field — it bills `Amount` in the currency
 * the merchant account is provisioned with. Without a guard a Money in another
 * currency is silently rebranded, so JPY 5000 (about $32) would be charged as
 * $5,000 on a USD account.
 *
 * Exercises the trait through a minimal request so the assertions are about
 * formatMoney and the account-currency resolution alone, not about whatever else
 * a concrete request validates.
 */
final class AccountCurrencyProbe extends AbstractRequest
{
    use ConnexPayRequestParameters;

    public function format(Money $money): string
    {
        return $this->formatMoney($money);
    }

    public function getData(): array
    {
        return [];
    }

    public function sendData($data): never
    {
        throw new LogicException('probe never sends');
    }
}

function accountCurrencyProbe(?string $accountCurrency = null): AccountCurrencyProbe
{
    $probe = new AccountCurrencyProbe(new OmnipayClient, new HttpRequest);
    if ($accountCurrency !== null) {
        $probe->setAccountCurrency($accountCurrency);
    }

    return $probe;
}

it('defaults the account currency to USD when unset, empty or blank', function (?string $configured) {
    expect(accountCurrencyProbe($configured)->getAccountCurrency())->toBe('USD');
})->with([null, '', '   ']);

it('reads the configured account currency, case-insensitively', function () {
    expect(accountCurrencyProbe('cad')->getAccountCurrency())->toBe('CAD')
        ->and(accountCurrencyProbe(' eur ')->getAccountCurrency())->toBe('EUR');
});

it('formats an amount that matches the account currency', function () {
    expect(accountCurrencyProbe()->format(new Money(1050, new Currency('USD'))))->toBe('10.50')
        ->and(accountCurrencyProbe('GBP')->format(new Money(1050, new Currency('GBP'))))->toBe('10.50');
});

it('refuses an amount whose currency is not the account currency', function (string $account, string $amount) {
    expect(fn () => accountCurrencyProbe($account)->format(new Money(5000, new Currency($amount))))
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
    expect(fn () => accountCurrencyProbe($code)->getAccountCurrency())
        ->toThrow(InvalidArgumentException::class, 'does not acquire in');
})->with(['JPY', 'CHF', 'AUD', 'XXX', 'US']);

it('rejects a mismatched amount even when both currencies share a scale', function () {
    // So the guard cannot be mistaken for a formatting concern: USD and CAD both
    // have two minor digits, yet the amount must still be refused.
    expect(fn () => accountCurrencyProbe('USD')->format(new Money(1050, new Currency('CAD'))))
        ->toThrow(InvalidArgumentException::class);
});
