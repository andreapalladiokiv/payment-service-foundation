<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use GuzzleHttp\Exception\GuzzleException;
use Money\Money;
use Omnipay\Common\Message\AbstractRequest;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\ConnexPay\Concern\ConnexPayRequestParameters;
use Techork\PaymentService\Gateway\ValueObject\CardSpendCategory;
use Techork\PaymentService\Gateway\ValueObject\PurchaseTypeBridge;

/**
 * Issues a virtual card via ConnexPay Purchases API.
 *
 * POST /api/v1/IssueCard
 * Base URL: sandboxpurchasesapi.connexpay.com (sandbox) / purchasesapi.connexpay.com (production)
 */
final class IssueVirtualCardRequest extends AbstractRequest
{
    use ConnexPayRequestParameters;

    public function getMerchantGuid(): string
    {
        return $this->getParameter('merchantGuid') ?? '';
    }

    public function setMerchantGuid(string $value): self
    {
        return $this->setParameter('merchantGuid', $value);
    }

    public function getFirstName(): ?string
    {
        return $this->getParameter('firstName');
    }

    public function setFirstName(?string $value): self
    {
        return $this->setParameter('firstName', $value);
    }

    public function getLastName(): ?string
    {
        return $this->getParameter('lastName');
    }

    public function setLastName(?string $value): self
    {
        return $this->setParameter('lastName', $value);
    }

    public function getSpendCategory(): string
    {
        return $this->getParameter('spendCategory') ?? '';
    }

    public function setSpendCategory(string $value): self
    {
        return $this->setParameter('spendCategory', $value);
    }

    public function getIncomingTransactionCode(): string
    {
        return $this->getParameter('incomingTransactionCode') ?? '';
    }

    public function setIncomingTransactionCode(string $value): self
    {
        return $this->setParameter('incomingTransactionCode', $value);
    }

    public function getCardBrand(): ?CardBrand
    {
        return $this->getParameter('cardBrand');
    }

    public function setCardBrand(?CardBrand $value): self
    {
        return $this->setParameter('cardBrand', $value);
    }

    public function getData(): array
    {
        $this->validate('money', 'merchantGuid', 'incomingTransactionCode', 'spendCategory');

        /** @var Money $money */
        $money = $this->getParameter('money');

        $data = [
            'MerchantGuid' => $this->getParameter('merchantGuid'),
            'AmountLimit' => (float) $this->formatMoney($money),
            'FirstName' => $this->getParameter('firstName') ?? 'N/A',
            'LastName' => $this->getParameter('lastName') ?? 'N/A',
            'PurchaseType' => $this->resolvePurchaseTypeCode(),
            'IncomingTransactionCode' => $this->getIncomingTransactionCode(),
            'ReturnCardData' => true,
        ];

        $brand = $this->normalizedCardBrand();
        if ($brand !== null) {
            $data['CardBrand'] = $brand;
        }

        return $this->withOrderNumber($data);
    }

    /**
     * ConnexPay expects a 2-digit MCC-style numeric `PurchaseType` ('01' =
     * Airline, '02' = HotelAndResort, …). Our domain {@see CardSpendCategory}
     * is converted to the legacy {@see PurchaseType} via
     * {@see PurchaseTypeBridge::fromCategory}, then zero-padded.
     */
    private function resolvePurchaseTypeCode(): string
    {
        $raw = $this->getSpendCategory();
        $category = CardSpendCategory::tryFrom($raw)
            ?? throw new \InvalidArgumentException("Unknown CardSpendCategory '{$raw}'");

        $purchaseType = PurchaseTypeBridge::fromCategory($category);

        return str_pad((string) $purchaseType->value, 2, '0', STR_PAD_LEFT);
    }

    /**
     * ConnexPay expects PascalCase brand names ('Visa', 'Mastercard'). Other
     * networks from the domain {@see CardBrand} enum are not supported by
     * ConnexPay's virtual card issuer; null lets the issuer pick.
     */
    private function normalizedCardBrand(): ?string
    {
        return match ($this->getCardBrand()) {
            null => null,
            CardBrand::Visa => 'Visa',
            CardBrand::Mastercard => 'Mastercard',
            default => throw new \InvalidArgumentException(
                "Unsupported ConnexPay card brand: {$this->getCardBrand()->value}"
            ),
        };
    }

    public function sendData($data): IssueVirtualCardResponse
    {
        try {
            $response = $this->getConnexPayClient()->post('/api/v1/IssueCard', $data);

            return new IssueVirtualCardResponse($this, $response);
        } catch (GuzzleException $e) {
            return new IssueVirtualCardResponse($this, [
                'cardGuid' => null,
                'status' => $e->getMessage(),
            ]);
        }
    }
}
