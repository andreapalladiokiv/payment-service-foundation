<?php

declare(strict_types=1);

namespace Techork\PaymentService\Revolut;

use GuzzleHttp\Exception\GuzzleException;
use Money\Money;
use Omnipay\Common\Message\AbstractRequest;
use Techork\PaymentService\Gateway\ValueObject\CardSpendCategory;
use Techork\PaymentService\Revolut\Concern\MerchantCategoryMapper;
use Techork\PaymentService\Revolut\Concern\RevolutRequestParameters;

/**
 * Updates an issued virtual card's spend controls.
 *
 * PATCH /api/1.0/cards/{cardId} — accepts the fields the issuer changes in
 * practice: `spending_limits` (mapped from the requested amount) and, when
 * a recognised spend category is supplied, the `categories` allow-list.
 * Requires `money` and `transactionReference` (the card id).
 */
final class UpdateVirtualCardRequest extends AbstractRequest
{
    use RevolutRequestParameters;

    public function getData(): array
    {
        $this->validate('money', 'transactionReference');

        /** @var Money $money */
        $money = $this->getParameter('money');

        $body = [
            'spending_limits' => $this->buildSpendingLimits($money),
        ];

        $categories = $this->resolveCategories();
        if ($categories !== []) {
            $body['categories'] = $categories;
        }

        return $body;
    }

    public function sendData($data): UpdateVirtualCardResponse
    {
        $cardId = (string) $this->getTransactionReference();

        try {
            $card = $this->getRevolutClient()->patch("/api/1.0/cards/{$cardId}", $data);
        } catch (GuzzleException $e) {
            return new UpdateVirtualCardResponse($this, [
                'id' => $cardId,
                'error' => $e->getMessage(),
            ]);
        }

        // PATCH echoes the updated card; fall back to the requested id so a
        // sparse body still resolves to the card we updated.
        return new UpdateVirtualCardResponse($this, $card + ['id' => $cardId]);
    }

    /**
     * @return list<string>
     */
    private function resolveCategories(): array
    {
        $raw = $this->getSpendCategory();
        if ($raw === null || $raw === '') {
            return [];
        }

        $category = CardSpendCategory::tryFrom($raw);
        if ($category === null) {
            return [];
        }

        return MerchantCategoryMapper::fromCategory($category);
    }
}
