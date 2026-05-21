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
 * Issues a virtual card via ConfermaPay deployments API.
 *
 * POST /deployments/v1/deployments
 *
 * Conferma is pre-funded from a card pool tied to {@see clientAccountCode} —
 * unlike ConnexPay, the gateway is not handed an `IncomingTransactionCode`
 * derived from a captured sale. The platform-side payment-intent linkage
 * lives one layer up; here {@see transactionReference} is forwarded as
 * `consumerReference` purely for traceability.
 */
final class IssueVirtualCardRequest extends AbstractRequest
{
    use ConfermaRequestParameters;

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

    public function getSupplierName(): ?string
    {
        return $this->getParameter('supplierName') ?? $this->getDefaultSupplierName();
    }

    public function setSupplierName(?string $value): self
    {
        return $this->setParameter('supplierName', $value);
    }

    public function getSpendCategory(): string
    {
        $value = $this->getParameter('spendCategory');

        return match (true) {
            $value instanceof CardSpendCategory => $value->value,
            is_string($value) => $value,
            default => '',
        };
    }

    public function setSpendCategory(CardSpendCategory|string $value): self
    {
        return $this->setParameter('spendCategory', $value instanceof CardSpendCategory ? $value->value : $value);
    }

    public function getData(): array
    {
        $this->validate('money', 'clientAccountValue', 'clientAccountType', 'spendCategory');

        /** @var Money $money */
        $money = $this->getParameter('money');

        $supplierName = $this->getSupplierName();
        if ($supplierName === null || $supplierName === '') {
            throw new \InvalidArgumentException(
                'ConfermaPay requires a supplier.name on every deployment. '
                .'Provide a per-call `supplierName` parameter or configure `defaultSupplierName` on the gateway.'
            );
        }

        $data = [
            'clientAccountCode' => [
                'type' => $this->getClientAccountType(),
                'value' => $this->getClientAccountValue(),
            ],
            'spendType' => $this->resolveSpendType(),
            'deploymentAmount' => [
                'value' => (float) $this->formatMoney($money),
                'currency' => $money->getCurrency()->getCode(),
            ],
            'paymentRange' => $this->buildPaymentRange(),
            'supplier' => ['name' => $supplierName],
        ];

        $consumerReference = $this->getParameter('transactionReference') ?? $this->getClientUniqueId();
        if ($consumerReference !== null && $consumerReference !== '') {
            $data['consumerReference'] = (string) $consumerReference;
        }

        $customer = $this->buildCustomer();
        if ($customer !== []) {
            $data['customer'] = $customer;
        }

        return $data;
    }

    public function sendData($data): IssueVirtualCardResponse
    {
        try {
            $response = $this->getConfermaClient()->post('/deployments/v1/deployments', $data);

            return new IssueVirtualCardResponse($this, $response);
        } catch (GuzzleException $e) {
            return new IssueVirtualCardResponse($this, [
                'id' => null,
                'status' => 'Failed',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Maps the domain {@see CardSpendCategory} (string-backed) onto the
     * Conferma `spendType` body field. Unknown / empty values fall back
     * to `Generic` — the safest classification when the caller did not
     * supply enough information for a more specific bucket.
     */
    private function resolveSpendType(): string
    {
        $category = CardSpendCategory::tryFrom($this->getSpendCategory());

        return $category === null
            ? SpendTypeMapper::GENERIC
            : SpendTypeMapper::fromCategory($category);
    }

    /**
     * @return array<string, string>
     */
    private function buildCustomer(): array
    {
        $first = $this->getFirstName();
        $last = $this->getLastName();

        return array_filter([
            'firstName' => $first,
            'lastName' => $last,
        ], static fn ($v) => $v !== null && $v !== '');
    }
}
