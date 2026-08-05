<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use Omnipay\Common\Message\AbstractResponse;
use Override;

final class TerminateCardResponse extends AbstractResponse
{
    #[Override]
    public function isSuccessful(): bool
    {
        return ($this->data['terminated'] ?? false) === true;
    }

    #[Override]
    public function getTransactionReference(): ?string
    {
        return $this->data['cardGuid'] ?? null;
    }

    #[Override]
    public function getMessage(): ?string
    {
        return $this->data['message'] ?? null;
    }
}
