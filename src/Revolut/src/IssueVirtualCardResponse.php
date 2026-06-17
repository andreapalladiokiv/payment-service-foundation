<?php

declare(strict_types=1);

namespace Techork\PaymentService\Revolut;

use Omnipay\Common\Message\AbstractResponse;
use Techork\PaymentService\Gateway\Contract\VirtualCardResponseInterface;
use Techork\PaymentService\Gateway\Contract\VirtualCardResult;

/**
 * Wraps the `POST /api/1.0/cards` response (optionally merged with
 * sensitive details). Maps Revolut's card object onto the platform
 * {@see VirtualCardResult}: `id` → cardGuid, `pan`/`cvv` (when fetched) →
 * cardNumber/cvv, `expiry` ("MM/YYYY") → expirationDate, `state` → status.
 */
final class IssueVirtualCardResponse extends AbstractResponse implements VirtualCardResponseInterface
{
    public function isSuccessful(): bool
    {
        return ! isset($this->data['error']) && ($this->data['id'] ?? null) !== null;
    }

    public function getTransactionReference(): ?string
    {
        return $this->data['id'] ?? null;
    }

    public function getMessage(): ?string
    {
        return $this->data['error'] ?? $this->data['message'] ?? null;
    }

    public function toVirtualCardResult(): VirtualCardResult
    {
        if (! $this->isSuccessful()) {
            return VirtualCardResult::failed($this->getMessage() ?? 'Revolut card issuance failed.');
        }

        return VirtualCardResult::succeeded(
            cardGuid: (string) $this->data['id'],
            cardNumber: $this->data['pan'] ?? null,
            cvv: $this->data['cvv'] ?? null,
            expirationDate: $this->data['expiry'] ?? null,
            status: $this->data['state'] ?? null,
        );
    }
}
