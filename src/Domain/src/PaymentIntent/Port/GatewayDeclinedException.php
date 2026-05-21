<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Port;

/**
 * Raised by a port when the gateway refuses an operation. After domain
 * invariants pass, a refusal is an exceptional condition (issuer decline,
 * fraud rule, capture window expired, ...) — not a regular return value.
 *
 * The aggregate catches this to record a {@see \Techork\PaymentService\Domain\PaymentIntent\Event\PaymentIntentFailed}
 * event; everything outside the aggregate may let it propagate.
 */
final class GatewayDeclinedException extends \DomainException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Gateway declined: {$reason}");
    }
}
