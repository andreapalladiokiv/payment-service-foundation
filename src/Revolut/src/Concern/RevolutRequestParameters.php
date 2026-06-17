<?php

declare(strict_types=1);

namespace Techork\PaymentService\Revolut\Concern;

use Money\Currencies\ISOCurrencies;
use Money\Formatter\DecimalMoneyFormatter;
use Money\Money;
use Techork\PaymentService\Revolut\RevolutHttpClientInterface;

trait RevolutRequestParameters
{
    public function getRevolutClient(): RevolutHttpClientInterface
    {
        return $this->getParameter('revolutClient');
    }

    public function setRevolutClient(RevolutHttpClientInterface $value): self
    {
        return $this->setParameter('revolutClient', $value);
    }

    public function setMoney(Money $value): self
    {
        return $this->setParameter('money', $value);
    }

    public function getMoney(): ?Money
    {
        return $this->getParameter('money');
    }

    public function setClientUniqueId(?string $value): self
    {
        return $this->setParameter('clientUniqueId', $value);
    }

    public function getClientUniqueId(): ?string
    {
        return $this->getParameter('clientUniqueId');
    }

    // transactionReference (the card id for update / terminate) uses
    // Omnipay AbstractRequest's built-in get/setTransactionReference
    // accessors — redeclaring them with stricter signatures is a fatal
    // incompatibility, so they are intentionally not defined here.

    public function setHolderId(?string $value): self
    {
        return $this->setParameter('holderId', $value);
    }

    public function getHolderId(): ?string
    {
        return $this->getParameter('holderId');
    }

    public function setAccountId(?string $value): self
    {
        return $this->setParameter('accountId', $value);
    }

    public function getAccountId(): ?string
    {
        return $this->getParameter('accountId');
    }

    public function setSpendCategory(?string $value): self
    {
        return $this->setParameter('spendCategory', $value);
    }

    public function getSpendCategory(): ?string
    {
        return $this->getParameter('spendCategory');
    }

    public function setFirstName(?string $value): self
    {
        return $this->setParameter('firstName', $value);
    }

    public function getFirstName(): ?string
    {
        return $this->getParameter('firstName');
    }

    public function setLastName(?string $value): self
    {
        return $this->setParameter('lastName', $value);
    }

    public function getLastName(): ?string
    {
        return $this->getParameter('lastName');
    }

    public function setLabel(?string $value): self
    {
        return $this->setParameter('label', $value);
    }

    public function getLabel(): ?string
    {
        return $this->getParameter('label');
    }

    /**
     * Which Revolut spend-limit bucket the requested amount maps to:
     * `single` (per-transaction) or a periodic window
     * (`day`/`week`/`month`/`quarter`/`year`/`all_time`). Defaults to
     * `single` — a virtual card issued for one booking should cap each
     * authorisation at the deployment amount.
     */
    public function setSpendLimitPeriod(?string $value): self
    {
        return $this->setParameter('spendLimitPeriod', $value);
    }

    public function getSpendLimitPeriod(): string
    {
        $period = $this->getParameter('spendLimitPeriod');

        return is_string($period) && $period !== '' ? $period : 'single';
    }

    /**
     * Optional validity window in days. When > 0 the card is created with a
     * `spending_period` that terminates the card on expiry — mirrors the
     * legacy issuer's open-to-spend window.
     */
    public function setValidityDays(?int $value): self
    {
        return $this->setParameter('validityDays', $value);
    }

    public function getValidityDays(): ?int
    {
        $days = $this->getParameter('validityDays');

        return $days === null ? null : (int) $days;
    }

    /**
     * Whether {@see IssueVirtualCardRequest} follows up the create call with
     * `GET /cards/{id}/sensitive-details` to surface the PAN + CVV. Requires
     * the `READ_SENSITIVE_CARD_DATA` scope and IP allow-listing; defaults to
     * true because a virtual card is only useful to the consumer once its
     * PAN is known.
     */
    public function setFetchSensitiveDetails(?bool $value): self
    {
        return $this->setParameter('fetchSensitiveDetails', $value);
    }

    public function getFetchSensitiveDetails(): bool
    {
        $value = $this->getParameter('fetchSensitiveDetails');

        return $value === null ? true : (bool) $value;
    }

    /**
     * Revolut spend limits are major-unit decimals (e.g. 200.22). The
     * decimal formatter preserves precision; callers cast to float for the
     * JSON number the API expects.
     */
    protected function formatMoney(Money $money): string
    {
        return (new DecimalMoneyFormatter(new ISOCurrencies))->format($money);
    }

    /**
     * Builds the `spending_limits` object for the configured period:
     * `{ "<period>": { "amount": <float>, "currency": "<ISO>" } }`.
     *
     * @return array<string, array{amount: float, currency: string}>
     */
    protected function buildSpendingLimits(Money $money): array
    {
        return [
            $this->getSpendLimitPeriod() => [
                'amount' => (float) $this->formatMoney($money),
                'currency' => $money->getCurrency()->getCode(),
            ],
        ];
    }
}
