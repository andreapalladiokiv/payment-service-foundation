<?php

declare(strict_types=1);

namespace Techork\PaymentService\Conferma;

use Omnipay\Common\Message\AbstractResponse;

final class TerminateCardResponse extends AbstractResponse
{
    public function isSuccessful(): bool
    {
        return ($this->data['terminated'] ?? false) === true;
    }

    public function getTransactionReference(): ?string
    {
        return $this->data['cardGuid'] ?? null;
    }

    public function getMessage(): ?string
    {
        return $this->data['message'] ?? $this->data['error'] ?? $this->data['status'] ?? null;
    }
}
