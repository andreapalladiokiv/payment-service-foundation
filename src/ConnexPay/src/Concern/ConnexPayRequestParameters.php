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

    /**
     * The `Card.ThreeDS` block, or null when this operation carries no
     * authentication result.
     *
     * ConnexPay accepts this on `/api/v1/verify` as well as on sales and
     * auth-onlys, so a card registration that was authenticated must forward it
     * too — otherwise the authentication is performed and then thrown away, and
     * the issuer sees an unauthenticated verification.
     */
    protected function formatThreeDS(): ?array
    {
        $threeDS = $this->getThreeDS();

        if ($threeDS === null) {
            return null;
        }

        return [
            'Cavv' => $threeDS->authenticationValue,
            'Version' => $threeDS->version?->value,
            'DirectoryServerTransactionID' => (string) $threeDS->dsTransactionId,
            'AcsTransactionId' => (string) $threeDS->acsTransactionId,
            'ECI' => $threeDS->eci?->value,
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
        if (class_exists(\Transliterator::class)) {
            $result = \Transliterator::create('Any-Latin; Latin-ASCII')?->transliterate($value);

            if (is_string($result)) {
                return $result;
            }
        }

        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT', $value);

        return $ascii === false ? $value : $ascii;
    }
}
