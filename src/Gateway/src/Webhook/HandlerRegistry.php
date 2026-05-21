<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Webhook;

use Techork\PaymentService\Gateway\Webhook\Contract\WebhookEventHandler;

/**
 * Maps (gateway kind, event type) → {@see WebhookEventHandler}. Registered
 * once by the host and consumed by {@see WebhookRouter}.
 *
 * The registry owns instances so the router doesn't touch the container.
 */
final class HandlerRegistry
{
    /** @var array<string, array<string, WebhookEventHandler>> */
    private array $handlers = [];

    public function register(string $kind, string $eventType, WebhookEventHandler $handler): void
    {
        $this->handlers[strtolower($kind)][$eventType] = $handler;
    }

    public function resolve(string $kind, string $eventType): ?WebhookEventHandler
    {
        return $this->handlers[strtolower($kind)][$eventType] ?? null;
    }
}
