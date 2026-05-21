<?php

declare(strict_types=1);

namespace Techork\PaymentService\Conferma;

use GuzzleHttp\Exception\GuzzleException;
use Money\Money;
use Omnipay\Common\Message\AbstractRequest;
use Techork\PaymentService\Conferma\Concern\ConfermaRequestParameters;
use Techork\PaymentService\Conferma\Concern\SpendTypeMapper;
use Techork\PaymentService\Gateway\ValueObject\CardSpendCategory;

/**
 * Updates a previously-issued virtual card (deployment) — currently
 * only the deployment amount and spend type. Maps to
 * `PUT /deployments/v1/deployments/{id}`.
 *
 * The exact body shape for amend operations is gated behind the
 * authenticated developer portal — confirm field names with the
 * Conferma integration team and update accordingly.
 */
final class UpdateVirtualCardRequest extends AbstractRequest
{
    use ConfermaRequestParameters;

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
            'spendType' => $this->resolveSpendType(),
            'deploymentAmount' => [
                'value' => (float) $this->formatMoney($money),
                'currency' => $money->getCurrency()->getCode(),
            ],
        ];
    }

    public function sendData($data): UpdateVirtualCardResponse
    {
        $cardGuid = (string) $this->getParameter('transactionReference');

        try {
            $response = $this->getConfermaClient()->put("/deployments/v1/deployments/{$cardGuid}", $data);

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

    private function resolveSpendType(): string
    {
        $raw = $this->getSpendCategory();
        $category = CardSpendCategory::tryFrom($raw);

        return $category === null
            ? SpendTypeMapper::GENERIC
            : SpendTypeMapper::fromCategory($category);
    }
}
