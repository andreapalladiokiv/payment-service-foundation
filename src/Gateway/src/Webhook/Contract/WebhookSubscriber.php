<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Webhook\Contract;

use Techork\PaymentService\Gateway\Webhook\HandlerRegistry;
use Techork\PaymentService\Gateway\Webhook\VerifierRegistry;

/**
 * A gateway package's active contribution to the webhook layer. Each
 * gateway ships a subscriber and declares it in its `composer.json`:
 *
 *     "extra": { "laravel": { "webhook": "Techork\\...\\StripeWebhookSubscriber" } }
 *
 * {@see \Techork\PaymentService\Laravel\Webhook\WebhookServiceProvider}
 * discovers all installed subscribers (mirroring the gateway-factory
 * discovery in {@see \Techork\PaymentService\Laravel\GatewayServiceProvider})
 * and asks each to {@see subscribe} itself onto the shared registries.
 *
 * Subscribers receive their verifier / parser / handler instances through
 * Laravel's container — the constructor declares them as plain
 * dependencies. Inside `subscribe()` the implementation chooses which
 * event types to wire and to which handlers, keeping the full mapping
 * in one place per gateway.
 *
 * Visitor-flavoured: each subscriber actively walks the registries and
 * pushes registrations in, rather than the registries pulling a static
 * description from the subscriber. This keeps DI explicit and avoids
 * shipping class-strings around at boot time.
 */
interface WebhookSubscriber
{
    public function subscribe(VerifierRegistry $verifiers, HandlerRegistry $handlers): void;
}
