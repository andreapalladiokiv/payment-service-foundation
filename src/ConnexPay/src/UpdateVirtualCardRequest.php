<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use GuzzleHttp\Exception\GuzzleException;
use Money\Money;
use Omnipay\Common\Message\AbstractRequest;
use Techork\PaymentService\ConnexPay\Concern\ConnexPayRequestParameters;
use Techork\PaymentService\Gateway\ValueObject\CardSpendCategory;
use Techork\PaymentService\Gateway\ValueObject\PurchaseTypeBridge;

/**
 * Updates a previously-issued virtual card (spend limit / spend category).
 *
 * PUT /api/v1/IssueCard/{guid} on the Purchases API. Accepts only fields the
 * legacy integration ever changed in production — `AmountLimit` and
 * `PurchaseType` (mapped from our domain {@see CardSpendCategory}). Other
 * ConnexPay-supported edits (suspend / unsuspend, cardholder name) are
 * intentionally out of scope until requested.
 */
final class UpdateVirtualCardRequest extends AbstractRequest
{
    use ConnexPayRequestParameters;

    public function getSpendCategory(): string
    {
        return $this->getParameter('spendCategory') ?? '';
    }

    public function setSpendCategory(string $value): self
    {
        return $this->setParameter('spendCategory', $value);
    }

    public function getData(): array
    {
        $this->validate('money', 'transactionReference', 'spendCategory');

        /** @var Money $money */
        $money = $this->getParameter('money');

        return [
            'AmountLimit' => (float) $this->formatMoney($money),
            'PurchaseType' => $this->resolvePurchaseTypeCode(),
        ];
    }

    public function sendData($data): UpdateVirtualCardResponse
    {
        $cardGuid = (string) $this->getParameter('transactionReference');

        try {
            $response = $this->getConnexPayClient()->put("/api/v1/IssueCard/{$cardGuid}", $data);

            return new UpdateVirtualCardResponse($this, [
                ...$response,
                'cardGuid' => $cardGuid,
            ]);
        } catch (GuzzleException $e) {
            return new UpdateVirtualCardResponse($this, [
                'cardGuid' => $cardGuid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolvePurchaseTypeCode(): string
    {
        $raw = $this->getSpendCategory();
        $category = CardSpendCategory::tryFrom($raw)
            ?? throw new \InvalidArgumentException("Unknown CardSpendCategory '{$raw}'");

        $purchaseType = PurchaseTypeBridge::fromCategory($category);

        return str_pad((string) $purchaseType->value, 2, '0', STR_PAD_LEFT);
    }
}
