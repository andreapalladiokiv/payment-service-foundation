<?php

declare(strict_types=1);

namespace Techork\PaymentService\Conferma\Concern;

use Money\Currencies\ISOCurrencies;
use Money\Formatter\DecimalMoneyFormatter;
use Money\Money;
use Techork\PaymentService\Conferma\ConfermaHttpClientInterface;

trait ConfermaRequestParameters
{
    public function getConfermaClient(): ConfermaHttpClientInterface
    {
        return $this->getParameter('confermaClient');
    }

    public function setConfermaClient(ConfermaHttpClientInterface $value): self
    {
        return $this->setParameter('confermaClient', $value);
    }

    public function setMoney(Money $value): self
    {
        return $this->setParameter('money', $value);
    }

    public function getClientAccountType(): ?string
    {
        return $this->getParameter('clientAccountType');
    }

    public function setClientAccountType(?string $value): self
    {
        return $this->setParameter('clientAccountType', $value);
    }

    public function getClientAccountValue(): ?string
    {
        return $this->getParameter('clientAccountValue');
    }

    public function setClientAccountValue(?string $value): self
    {
        return $this->setParameter('clientAccountValue', $value);
    }

    public function getDefaultSupplierName(): ?string
    {
        return $this->getParameter('defaultSupplierName');
    }

    public function setDefaultSupplierName(?string $value): self
    {
        return $this->setParameter('defaultSupplierName', $value);
    }

    public function getPaymentRangeDays(): int
    {
        return (int) ($this->getParameter('paymentRangeDays') ?? 30);
    }

    public function setPaymentRangeDays(?int $value): self
    {
        return $this->setParameter('paymentRangeDays', $value);
    }

    public function setClientUniqueId(?string $value): self
    {
        return $this->setParameter('clientUniqueId', $value);
    }

    public function getClientUniqueId(): ?string
    {
        return $this->getParameter('clientUniqueId');
    }

    /**
     * Conferma `deploymentAmount.value` is documented as numeric;
     * Decimal money formatter returns the major-unit string ("123.45")
     * which keeps precision under JSON encode and is widely accepted by
     * Conferma's REST API as numeric or string.
     */
    protected function formatMoney(Money $money): string
    {
        return (new DecimalMoneyFormatter(new ISOCurrencies))->format($money);
    }

    /**
     * @return array{startDate: string, endDate: string}
     */
    protected function buildPaymentRange(): array
    {
        $start = new \DateTimeImmutable;
        $end = $start->modify('+'.$this->getPaymentRangeDays().' days');

        return [
            'startDate' => $start->format('Y-m-d'),
            'endDate' => $end->format('Y-m-d'),
        ];
    }
}
