<?php

declare(strict_types=1);

namespace Techork\PaymentService\Conferma;

use Omnipay\Common\Message\AbstractResponse;
use Techork\PaymentService\Gateway\Contract\VirtualCardResponseInterface;
use Techork\PaymentService\Gateway\Contract\VirtualCardResult;

final class UpdateVirtualCardResponse extends AbstractResponse implements VirtualCardResponseInterface
{
    public function isSuccessful(): bool
    {
        return ($this->data['cardGuid'] ?? null) !== null && ($this->data['error'] ?? null) === null;
    }

    public function getTransactionReference(): ?string
    {
        return $this->data['cardGuid'] ?? $this->data['id'] ?? null;
    }

    public function getMessage(): ?string
    {
        return $this->data['error'] ?? $this->data['message'] ?? $this->data['status'] ?? null;
    }

    public function toVirtualCardResult(): VirtualCardResult
    {
        if (! $this->isSuccessful()) {
            $message = $this->getMessage() ?? json_encode($this->data);

            return VirtualCardResult::failed($message ?: 'Virtual card update failed.');
        }

        return VirtualCardResult::succeeded(
            cardGuid: $this->getTransactionReference(),
            status: $this->data['status'] ?? null,
        );
    }
}
