<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay\Concern;

use Techork\PaymentService\ConnexPay\ConnexPayHttpClientInterface;
use InvalidArgumentException;
use Money\Currencies\ISOCurrencies;
use Money\Formatter\DecimalMoneyFormatter;
use Money\Money;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Transliterator;

trait ConnexPayRequestParameters
{
    /**
     * Currencies ConnexPay accepts payments in, per its acquiring product page
     * ("four native currencies USD, CAD, GBP, and EUR"). Deliberately NOT the
     * 30-code list from the Currency and Region Codes reference — that one is
     * scoped to the Issue Card / Issue Lite endpoints, i.e. card issuing.
     *
     * The list exists so a typo or an issuing-only currency in `account_currency`
     * fails loudly instead of waving {@see formatMoney} through: matching an
     * amount against a currency ConnexPay cannot acquire in would reinstate the
     * very mis-billing the check prevents.
     */
    private const array CONNEXPAY_ACQUIRING_CURRENCIES = ['USD', 'CAD', 'GBP', 'EUR'];

    public function getConnexPayClient(): ConnexPayHttpClientInterface
    {
        return $this->getParameter('connexPayClient');
    }

    public function setConnexPayClient(ConnexPayHttpClientInterface $value): self
    {
        return $this->setParameter('connexPayClient', $value);
    }

    public function getDeviceGuid(): string
    {
        return $this->getParameter('deviceGuid') ?? '';
    }

    public function setDeviceGuid(string $value): self
    {
        return $this->setParameter('deviceGuid', $value);
    }

    /**
     * Storefront name for the hosted payment page, inherited from the gateway's
     * `merchant_name` credential through createRequest's parameter merge. Only
     * the hosted path reads it.
     */
    public function getMerchantName(): string
    {
        return (string) ($this->getParameter('merchantName') ?? '');
    }

    public function setMerchantName(string $value): self
    {
        return $this->setParameter('merchantName', $value);
    }

    /**
     * `static`, not `self`: this overrides {@see \Omnipay\Common\Message\AbstractRequest::setMoney},
     * which is annotated `@return $this`. Naming the using class instead would promise a
     * fixed type where the parent promises the called one.
     */
    public function setMoney(Money $value): static
    {
        return $this->setParameter('money', $value);
    }

    public function setClientUniqueId(?string $value): self
    {
        return $this->setParameter('clientUniqueId', $value);
    }

    public function getClientUniqueId(): ?string
    {
        return $this->getParameter('clientUniqueId');
    }

    public function setBillingAddress(?BillingAddress $value): self
    {
        return $this->setParameter('billingAddress', $value);
    }

    public function setStatementDescription(?string $value): self
    {
        return $this->setParameter('statementDescription', $value);
    }

    public function getStatementDescription(): ?string
    {
        return $this->getParameter('statementDescription');
    }

    /**
     * Currency the ConnexPay merchant account is provisioned in — what ConnexPay
     * calls the "Accounting Currency". Comes from the `account_currency`
     * credential; empty means USD.
     *
     * Named `accountCurrency` rather than `currency` because Omnipay's
     * AbstractRequest and AbstractGateway both already define get/setCurrency().
     *
     * @throws InvalidArgumentException when configured to a currency ConnexPay
     *                                  does not acquire in — a misconfiguration
     *                                  must fail here, not silently disable the
     *                                  check in {@see formatMoney}.
     */
    public function getAccountCurrency(): string
    {
        $code = strtoupper(trim((string) ($this->getParameter('accountCurrency') ?? '')));

        if ($code === '') {
            return 'USD';
        }

        if (! in_array($code, self::CONNEXPAY_ACQUIRING_CURRENCIES, true)) {
            throw new InvalidArgumentException(sprintf(
                'account_currency is set to %s, which ConnexPay does not acquire in (it acquires in %s). '
                .'Issuing supports more currencies, acceptance does not.',
                $code,
                implode(', ', self::CONNEXPAY_ACQUIRING_CURRENCIES),
            ));
        }

        return $code;
    }

    public function setAccountCurrency(string $value): self
    {
        return $this->setParameter('accountCurrency', $value);
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
    protected function formatMoney(Money $money): string
    {
        $expected = $this->getAccountCurrency();
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

    /**
     * The caller's `clientUniqueId` IS the business order number for
     * ConnexPay — it lands on merchant-facing reports and is the only
     * Search/Sales filter that can later locate the transaction (guid
     * filters are silently ignored by that endpoint). Forward it on every
     * endpoint that accepts `OrderNumber`; omit the key when absent.
     *
     * The bridge ports suffix the aggregate id with ":capture" / ":cancel"
     * for gateways with idempotency-key semantics (see
     * PaymentGatewayInterface). ConnexPay has no such concept — repeating
     * the same OrderNumber across the auth, capture and void of one intent
     * is correct and keeps Search/Sales lookups working — so the synthetic
     * suffix is stripped rather than leaked into merchant-facing reports.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function withOrderNumber(array $data): array
    {
        $orderNumber = $this->getClientUniqueId();

        if ($orderNumber !== null && $orderNumber !== '') {
            $data['OrderNumber'] = preg_replace('/:(?:capture|cancel)$/', '', $orderNumber);
        }

        return $data;
    }

    /**
     * ConnexPay's duplicate detection, which `OrderNumber` never was.
     *
     * The docs make the split plain: `OrderNumber` is "commonly used for reporting on and
     * reconciling your PayIns and PayOuts", searchable in Bridge and carried into the
     * Chargeback Management System; `SequenceNumber` is the one to "provide a unique
     * SequenceNumber for each new request", where a repeat within thirty minutes "will be
     * considered a duplicate request". Sandbox agrees: two auth-onlys with one OrderNumber,
     * one amount and no SequenceNumber come back as two different guids — two holds on one
     * cardholder's card.
     *
     * So this keeps the `:capture` / `:cancel` suffix that {@see withOrderNumber} strips.
     * The two fields want opposite things from it: the order number ties one payment's
     * operations together for reporting, and the sequence number tells them apart so a
     * capture is not taken for the authorization it settles.
     *
     * Punctuation goes. The field is documented as 100 alpha-numeric characters and, unlike
     * `OrderNumber`, names no permitted specials — so a UUID's hyphens and the suffix's
     * colon are dropped rather than gambled on.
     *
     * What this does NOT buy is replay safety. The window is thirty minutes; a job retried
     * later authorizes again. That has to be stopped before the call ever leaves — see
     * {@see \Techork\PaymentService\Laravel\Port\OmnipayCreatePort}.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function withSequenceNumber(array $data): array
    {
        $clientUniqueId = $this->getClientUniqueId();

        if ($clientUniqueId === null || $clientUniqueId === '') {
            return $data;
        }

        $sequence = substr((string) preg_replace('/[^A-Za-z0-9]/', '', $clientUniqueId), 0, 100);

        if ($sequence !== '') {
            $data['SequenceNumber'] = $sequence;
        }

        return $data;
    }

    /**
     * Both of ConnexPay's identifiers, which every documented endpoint takes together and
     * which mean different things: one names the payment, the other names this request.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function withIdentifiers(array $data): array
    {
        return $this->withSequenceNumber($this->withOrderNumber($data));
    }

    protected function formatExpirationDate(string $month, string $year): string
    {
        return substr($year, -2).str_pad($month, 2, '0', STR_PAD_LEFT);
    }

    protected function formatRiskData(BillingAddress $address): array
    {
        return [
            'Name' => $address->firstName.' '.$address->lastName,
            'BillingPhoneNumber' => $address->phone ? (string) $address->phone : null,
            'BillingState' => $address->state ? (string) $address->state : null,
            'BillingCountryCode' => (string) $address->country,
            'Email' => $address->email ? (string) $address->email : null,
            'BillingAddress1' => $address->line,
            'BillingAddress2' => $address->lineExtra,
            'BillingPostalCode' => $address->postalCode,
        ];
    }

    protected function formatCustomer(BillingAddress $address): array
    {
        return [
            'FirstName' => $address->firstName,
            'LastName' => $address->lastName,
            'Phone' => $address->phone ? (string) $address->phone : null,
            'City' => self::transliterate($address->city),
            'State' => $address->state ? (string) $address->state : null,
            'Country' => (string) $address->country,
            'Email' => $address->email ? (string) $address->email : null,
            'Address1' => $address->line,
            'Address2' => $address->lineExtra,
            'Zip' => $address->postalCode,
        ];
    }

    /**
     * ConnexPay rejects non-ASCII input on Customer fields ("München",
     * "Kraków" fail validation), so fold accents down to their ASCII
     * equivalents. Prefers ext-intl (locale-independent), falls back to
     * iconv, and to the original value when neither can transliterate.
     */
    protected static function transliterate(string $value): string
    {
        if (class_exists(Transliterator::class)) {
            $result = Transliterator::create('Any-Latin; Latin-ASCII')?->transliterate($value);

            if (is_string($result)) {
                return $result;
            }
        }

        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT', $value);

        return $ascii === false ? $value : $ascii;
    }
}
