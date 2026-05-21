<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Contract;

interface VirtualCardResponseInterface
{
    public function toVirtualCardResult(): VirtualCardResult;
}
