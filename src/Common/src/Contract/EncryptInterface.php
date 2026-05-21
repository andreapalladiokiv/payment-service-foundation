<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\Contract;

interface EncryptInterface
{
    public function encrypt(string $data): string;
}
