<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Command;

use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Kill a virtual card.
 */
final readonly class TerminateCardCommand
{
    public function __construct(
        public GatewayId $gatewayId,
        public string $cardGuid,
    ) {}
}
