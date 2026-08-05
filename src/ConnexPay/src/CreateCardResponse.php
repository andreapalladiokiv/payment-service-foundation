<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use Override;
use Techork\PaymentService\Gateway\Contract\CustomerReferenceProvider;

final class CreateCardResponse extends ConnexPayResponse implements CustomerReferenceProvider
{
    #[Override]
    public function getCustomerReference(): ?string
    {
        return $this->data['customerGuid'] ?? null;
    }
}
