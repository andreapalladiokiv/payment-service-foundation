<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Webhook\Contract;

use Techork\PaymentService\Gateway\Webhook\WebhookRouter;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Outcome of {@see WebhookRouter::identifyGateway()}: the
 * signature-verified tenant plus the idempotency key extracted from the
 * payload.
 */
final readonly class GatewayMatch
{
    public function __construct(
        public GatewayId $gatewayId,
        public string $kind,
        public string $externalId,
    ) {}
}
