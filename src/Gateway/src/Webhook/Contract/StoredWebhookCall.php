<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Webhook\Contract;

use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Framework-agnostic payload sent from the host's stored webhook-call record
 * into the {@see \Techork\PaymentService\Gateway\Webhook\WebhookRouter}.
 *
 * The host (e.g. Laravel bridge's WebhookCall Eloquent model) knows about its
 * own storage; the router only needs the gateway kind, tenant id, and parsed
 * payload to re-parse the event and dispatch the handler.
 */
final readonly class StoredWebhookCall
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $kind,
        public GatewayId $gatewayId,
        public array $payload,
    ) {}
}
