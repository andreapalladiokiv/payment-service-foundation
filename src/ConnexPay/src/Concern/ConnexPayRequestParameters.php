<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay\Concern;

use Techork\PaymentService\ConnexPay\ConnexPayHttpClientInterface;
use Money\Currencies\ISOCurrencies;
use Money\Formatter\DecimalMoneyFormatter;
use Money\Money;
use Techork\PaymentService\Common\ValueObject\BillingAddress;

trait ConnexPayRequestParameters
{
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

    public function setMoney(Money $value): self
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

    protected function formatMoney(Money $money): string
    {
        return (new DecimalMoneyFormatter(new ISOCurrencies))->format($money);
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
            'City' => $address->city,
            'State' => $address->state ? (string) $address->state : null,
            'Country' => (string) $address->country,
            'Email' => $address->email ? (string) $address->email : null,
            'Address1' => $address->line,
            'Address2' => $address->lineExtra,
            'Zip' => $address->postalCode,
        ];
    }
}
