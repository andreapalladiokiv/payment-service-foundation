<?php

namespace Techork\PaymentService\Gateway\Logger;

use Stringable;

interface GatewayLoggerInterface
{
    public function log(string|Stringable $message, array $context = []): void;
}