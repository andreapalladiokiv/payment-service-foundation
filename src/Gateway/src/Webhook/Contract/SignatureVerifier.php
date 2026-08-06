<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Webhook\Contract;

use Techork\PaymentService\Gateway\Contract\GatewayCredential;

/**
 * Verifies the authenticity of an incoming webhook against one candidate credential.
 *
 * Takes an {@see InboundWebhook} rather than a PSR-7 request, and that is the whole of the
 * contract's opinion: a webhook is offered to every candidate credential until one authenticates,
 * so nothing a verifier does may change what the next verifier sees. A request carries a body
 * stream, and reading a stream moves it — which is how every credential after the first came to
 * be checked against an empty body. Values cannot be consumed, so the question does not arise.
 *
 * Implementations may stash auxiliary data (tenant id, event id) on whatever request-scoped state
 * the host framework uses — the contract here only reports whether the signature is valid.
 */
interface SignatureVerifier
{
    public function verify(InboundWebhook $webhook, GatewayCredential $gateway): bool;
}
