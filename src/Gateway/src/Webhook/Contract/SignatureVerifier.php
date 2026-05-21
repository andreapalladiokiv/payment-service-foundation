<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Webhook\Contract;

use Psr\Http\Message\ServerRequestInterface;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;

/**
 * Verifies the authenticity of an incoming webhook.
 *
 * Takes a PSR-7 request so the contract is framework-agnostic. Implementations
 * may stash auxiliary data (tenant id, event id) on whatever request-scoped
 * state the host framework uses — the contract here only reports whether the
 * signature is valid.
 */
interface SignatureVerifier
{
    public function verify(ServerRequestInterface $request, GatewayCredential $gateway): bool;
}
