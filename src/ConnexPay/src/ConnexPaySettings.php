<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use InvalidArgumentException;
use Money\Currencies\ISOCurrencies;
use Money\Formatter\DecimalMoneyFormatter;
use Money\Money;

/**
 * What the gateway's configuration contributes to a ConnexPay request, as opposed to what the
 * caller asked for.
 *
 * The split matters because these are deployment facts — which device the sale is booked
 * against, which merchant issues the cards, what name the buyer reads on the hosted page, which
 * currency the account is provisioned in — and none of them belong in a command. They used to
 * reach a request as seven more keys in the same array as the amount, merged in by
 * `createRequest()`, which is why a request had accessors that could not tell you whether a value
 * came from the tenant's credential row or from the caller.
 *
 * The account currency is not a plain field, which is why it is a method: ConnexPay's v1 API
 * carries no currency at all, so the only thing standing between a mis-typed credential and a
 * mis-billed payment is a check, and the check belongs next to the value it guards.
 */
final readonly class ConnexPaySettings
{
    /**
     * Currencies ConnexPay accepts payments in, per its acquiring product page
     * ("four native currencies USD, CAD, GBP, and EUR"). Deliberately NOT the
     * 30-code list from the Currency and Region Codes reference — that one is
     * scoped to the Issue Card / Issue Lite endpoints, i.e. card issuing.
     *
     * The list exists so a typo or an issuing-only currency in `account_currency`
     * fails loudly instead of waving {@see formatAmount} through: matching an
     * amount against a currency ConnexPay cannot acquire in would reinstate the
     * very mis-billing the check prevents.
     */
    private const array ACQUIRING_CURRENCIES = ['USD', 'CAD', 'GBP', 'EUR'];

    /**
     * @param  string  $merchantName  Display name shown to the buyer on ConnexPay's hosted payment
     *   page, from the `merchant_name` credential. Required by
     *   `POST /api/v1/HostedPaymentPageRequests` and used nowhere else, so it may stay empty on a
     *   merchant that never takes hosted payments — the hosted path itself refuses loudly rather
     *   than sending a blank name. This is a storefront name, not the card-statement descriptor:
     *   statement text arrives per-transaction as `statementDescription`.
     * @param  string  $accountCurrency  As configured, unnormalised; read it through
     *   {@see acquiringCurrency()} rather than directly.
     */
    public function __construct(
        public string $deviceGuid = '',
        public string $merchantGuid = '',
        public string $merchantName = '',
        public string $accountCurrency = '',
        public string $environment = 'sandbox',
    ) {}

    /**
     * Currency the ConnexPay merchant account is provisioned in — what ConnexPay
     * calls the "Accounting Currency". Comes from the `account_currency`
     * credential; empty means USD.
     *
     * @throws InvalidArgumentException when configured to a currency ConnexPay
     *                                  does not acquire in — a misconfiguration
     *                                  must fail here, not silently disable the
     *                                  check in {@see formatAmount}.
     */
    public function acquiringCurrency(): string
    {
        $code = strtoupper(trim($this->accountCurrency));

        if ($code === '') {
            return 'USD';
        }

        if (! in_array($code, self::ACQUIRING_CURRENCIES, true)) {
            throw new InvalidArgumentException(sprintf(
                'account_currency is set to %s, which ConnexPay does not acquire in (it acquires in %s). '
                .'Issuing supports more currencies, acceptance does not.',
                $code,
                implode(', ', self::ACQUIRING_CURRENCIES),
            ));
        }

        return $code;
    }

    /**
     * The v1 API carries no currency field at all — verified against its OpenAPI
     * source and empirically against the sandbox, where every currency spelling
     * we sent was silently dropped. ConnexPay bills whatever we put in `Amount`
     * in the account's own currency, so a Money in any other currency is
     * rebranded rather than rejected: ¥5,000 would bill as $5,000, and nothing
     * in the request, the response or the Sale webhook records what was meant.
     * Refuse it instead.
     */
    public function formatAmount(Money $money): string
    {
        $expected = $this->acquiringCurrency();
        $code = $money->getCurrency()->getCode();

        if ($code !== $expected) {
            throw new InvalidArgumentException(sprintf(
                'The ConnexPay account is provisioned in %s but the amount is %s; the API sends no '
                .'currency field, so this would be billed as %s. Route %s to another gateway.',
                $expected,
                $code,
                $expected,
                $code,
            ));
        }

        return new DecimalMoneyFormatter(new ISOCurrencies)->format($money);
    }

    public function isProduction(): bool
    {
        return $this->environment === 'production';
    }
}
