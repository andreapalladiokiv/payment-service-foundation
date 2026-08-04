<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Exception;

use BadMethodCallException;

/**
 * A gateway was asked for an operation its product does not have at all,
 * whatever the instrument — Paynet has no auth-only, Revolut acquires nothing.
 *
 * The degradation this must not break is narrower than it first looks. It lives
 * on `retryRefund()` — refunding onto an *alternative* instrument, which Stripe
 * and others have no native primitive for and which
 * {@see \Techork\PaymentService\Gateway\PaymentGatewayRouter::refund} expects to
 * fall through the catch as a failed `GatewayResult` so the aggregate records
 * `RefundFailed` and the saga carries on. That is step 2 of that method. It says
 * nothing about step 1, the plain refund.
 *
 * So the test is per operation, not per package: does a caller reaching this
 * method mean the gateway lacks a primitive for something it does otherwise
 * support (degrade), or does it mean the operation was routed to the wrong
 * gateway entirely (refuse)? Revolut's `UnsupportedOperationException` is the
 * second kind on every operation it throws for — it acquires nothing at all and
 * has no `retryRefund` — so it carries the marker.
 *
 * Paynet splits on the same test rather than by package: authorize,
 * createPaymentMethod, issueVirtualCard and terminateVirtualCard have no
 * legitimate caller and throw this, while `void()` keeps the unmarked
 * `UnsupportedPaynetOperation` because it backs `cancel()`, the only thing that
 * closes an abandoned hosted payment. Two refusals in one gateway, answering the
 * question differently, is the expected outcome of applying it per operation.
 */
final class UnsupportedOperation extends BadMethodCallException implements UnsupportedByGateway
{
    public static function forGateway(string $gatewayName, string $operation, string $because): self
    {
        return new self(sprintf(
            'Gateway "%s" does not support the "%s" operation: %s',
            $gatewayName,
            $operation,
            $because,
        ));
    }
}
