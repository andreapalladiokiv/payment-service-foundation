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
 *
 * Capture is the exception: {@see \Techork\PaymentService\Domain\PaymentIntent\PaymentIntentAggregate::capture}
 * does NOT catch it. Capturing an existing authorization has no business failure
 * mode — the money was reserved at authorization — so a refusal there is
 * infrastructural, the caller retries, and burying the intent in `Failed` would
 * assert something the aggregate cannot take back while the funds may still be
 * held.
 */
final class GatewayDeclinedException extends \DomainException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Gateway declined: {$reason}");
    }
}
