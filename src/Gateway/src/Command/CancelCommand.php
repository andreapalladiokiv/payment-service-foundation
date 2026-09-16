<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Command;

use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Release money that was reserved and will not be taken.
 */
final readonly class CancelCommand
{
    public function __construct(
        public GatewayId $gatewayId,
        public string $transactionReference,
        public ?string $clientUniqueId = null,
    ) {}
}
