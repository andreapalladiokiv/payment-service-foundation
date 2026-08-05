<?php

namespace Techork\PaymentService\Gateway\Logger;

use Override;
use Stringable;

final readonly class NullGatewayLogger implements GatewayLoggerInterface
{
    #[Override]
    public function log(Stringable|string $message, array $context = []): void
    {
    }
}