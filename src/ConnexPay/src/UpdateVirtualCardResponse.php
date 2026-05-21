<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use Techork\PaymentService\Gateway\Contract\VirtualCardResponseInterface;
use Techork\PaymentService\Gateway\Contract\VirtualCardResult;

/**
 * ConnexPay's `PUT /api/v1/IssueCard/{guid}` does not echo the full card
 * payload back; success is signalled by HTTP 200 with no `error` body. We
 * preserve the cardGuid we updated so callers can correlate.
 */
final class UpdateVirtualCardResponse extends ConnexPayResponse implements VirtualCardResponseInterface
{
    public function isSuccessful(): bool
    {
        return empty($this->data['error']);
    }

    public function getTransactionReference(): ?string
    {
        return $this->data['cardGuid'] ?? null;
    }

    public function getMessage(): ?string
    {
        return $this->data['error']
            ?? $this->data['message']
            ?? $this->data['processorResponseMessage']
            ?? null;
    }

    public function toVirtualCardResult(): VirtualCardResult
    {
        if (! $this->isSuccessful()) {
            return VirtualCardResult::failed($this->getMessage() ?? 'Virtual card update failed.');
        }

        return VirtualCardResult::succeeded(cardGuid: (string) $this->getTransactionReference());
    }
}
