<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Webhook\Contract;

use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Handles a single webhook event. Implementations must be idempotent — the
 * machinery may retry, and gateways may deliver the same event more than once.
 *
 * @template T as object
 */
interface WebhookEventHandler
{
    /**
     * @param T $event
     */
    public function __invoke(object $event, GatewayId $gatewayId): HandlerOutcome;
}
