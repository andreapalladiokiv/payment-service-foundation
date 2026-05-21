<?php

namespace Techork\PaymentService\Gateway\Logger;

use Stringable;

final readonly class NullGatewayLogger implements GatewayLoggerInterface
{
    public function log(Stringable|string $message, array $context = []): void
    {
    }
}