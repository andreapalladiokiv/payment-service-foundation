<?php

declare(strict_types=1);

namespace Techork\PaymentService\Conferma;

use Omnipay\Common\Message\AbstractResponse;
use Techork\PaymentService\Gateway\Contract\VirtualCardResponseInterface;
use Techork\PaymentService\Gateway\Contract\VirtualCardResult;

/**
 * Normalizes the Conferma `POST /deployments/v1/deployments` response
 * into our {@see VirtualCardResult}.
 *
 * Field names come from the production legacy implementation
 * (`payment-service/app/Infrastructure/ConfermaPay/CardIssuer.php`):
 *
 *   deploymentId
 *   consumerReference
 *   status                                    (Deployed / PreDeployment / Failed / ...)
 *   cardDetails.number
 *   cardDetails.cvv
 *   cardDetails.expiryYear                    (4-digit year)
 *   cardDetails.expiryMonth                   (1-12)
 *   deploymentAmountDetails.deploymentAmount.currency
 *
 * The {@see VirtualCardResult::expirationDate} field carries the
 * expiry as a single `MM/YY` string for cross-gateway uniformity;
 * year / month fields are reassembled here.
 */
final class IssueVirtualCardResponse extends AbstractResponse implements VirtualCardResponseInterface
{
    public function isSuccessful(): bool
    {
        if ($this->cardGuid() === null) {
            return false;
        }

        $status = $this->status();

        return $status === null || in_array($status, ['Deployed', 'PreDeployment'], true);
    }

    public function getTransactionReference(): ?string
    {
        return $this->cardGuid();
    }

    public function getMessage(): ?string
    {
        return $this->data['message']
            ?? $this->data['error']
            ?? $this->status();
    }

    public function toVirtualCardResult(): VirtualCardResult
    {
        if (! $this->isSuccessful()) {
            $message = $this->getMessage() ?? json_encode($this->data);

            return VirtualCardResult::failed($message ?: 'Virtual card issuance failed.');
        }

        return VirtualCardResult::succeeded(
            cardGuid: $this->cardGuid(),
            cardNumber: $this->cardNumber(),
            cvv: $this->cvv(),
            expirationDate: $this->expirationDate(),
            status: $this->status(),
        );
    }

    private function cardGuid(): ?string
    {
        return $this->data['deploymentId'] ?? $this->data['id'] ?? null;
    }

    private function status(): ?string
    {
        return $this->data['status'] ?? null;
    }

    private function cardNumber(): ?string
    {
        return $this->data['cardDetails']['number'] ?? null;
    }

    private function cvv(): ?string
    {
        return $this->data['cardDetails']['cvv'] ?? null;
    }

    /**
     * Conferma returns `expiryYear` (4-digit) and `expiryMonth` (1-12)
     * separately. Project-wide {@see VirtualCardResult::expirationDate}
     * carries a single `MM/YY` string — match that here.
     */
    private function expirationDate(): ?string
    {
        $year = $this->data['cardDetails']['expiryYear'] ?? null;
        $month = $this->data['cardDetails']['expiryMonth'] ?? null;

        if ($year === null || $month === null) {
            return null;
        }

        $yy = substr(str_pad((string) (int) $year, 4, '0', STR_PAD_LEFT), -2);
        $mm = str_pad((string) (int) $month, 2, '0', STR_PAD_LEFT);

        return "{$mm}/{$yy}";
    }
}
