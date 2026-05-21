<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\Contract;

interface DecryptInterface
{
    public function decrypt(string $data): string;
}
