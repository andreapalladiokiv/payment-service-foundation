<?php

declare(strict_types=1);

namespace Techork\PaymentService\Revolut;

use Omnipay\Common\Message\AbstractResponse;
use Techork\PaymentService\Gateway\Contract\VirtualCardResponseInterface;
use Techork\PaymentService\Gateway\Contract\VirtualCardResult;
use Techork\PaymentService\Revolut\Concern\RevolutExpiry;

/**
 * Wraps the `PATCH /api/1.0/cards/{cardId}` response. Success is the
 * absence of an `error` plus a resolvable card id; maps to a
 * {@see VirtualCardResult} carrying the card guid and current `state`.
 */
final class UpdateVirtualCardResponse extends AbstractResponse implements VirtualCardResponseInterface
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
            return VirtualCardResult::failed($this->getMessage() ?? 'Revolut card update failed.');
        }

        return VirtualCardResult::succeeded(
            cardGuid: (string) $this->data['id'],
            expirationDate: RevolutExpiry::normalize($this->data['expiry'] ?? null),
            status: $this->data['state'] ?? null,
        );
    }
}
