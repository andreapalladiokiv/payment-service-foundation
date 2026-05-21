<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use Techork\PaymentService\Gateway\Contract\VirtualCardResponseInterface;
use Techork\PaymentService\Gateway\Contract\VirtualCardResult;

final class IssueVirtualCardResponse extends ConnexPayResponse implements VirtualCardResponseInterface
{
    public function isSuccessful(): bool
    {
        return ! empty($this->data['card']['cardGuid']);
    }

    public function getTransactionReference(): ?string
    {
        return $this->data['card']['cardGuid'] ?? null;
    }

    public function getMessage(): ?string
    {
        return $this->data['message']
            ?? $this->data['processorResponseMessage']
            ?? $this->data['card']['status']
            ?? null;
    }

    public function toVirtualCardResult(): VirtualCardResult
    {
        if (! $this->isSuccessful()) {
            $message = $this->getMessage() ?? json_encode($this->data);
            return VirtualCardResult::failed($message ?: 'Virtual card issuance failed.');
        }

        $card = $this->data['card'];

        return VirtualCardResult::succeeded(
            cardGuid: $card['cardGuid'],
            cardNumber: $card['accountNumber'] ?? null,
            cvv: $card['securityCode'] ?? null,
            expirationDate: $card['expiration'] ?? null,
            status: $card['status'] ?? null,
        );
    }
}
