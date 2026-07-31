<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Exception;

use BadMethodCallException;

/**
 * A gateway was asked for an operation its product does not have at all,
 * whatever the instrument — Paynet has no auth-only, Revolut acquires nothing.
 *
 * Note the deliberate asymmetry with the older per-package exceptions
 * (`UnsupportedPaynetOperation`, Revolut's `UnsupportedOperationException`):
 * those do NOT carry the {@see UnsupportedByGateway} marker, so they keep
 * degrading into a failed result the way they always have. That is load-bearing
 * for at least one path — {@see \Techork\PaymentService\Gateway\PaymentGatewayRouter::refund}
 * documents that a gateway without a native retry-refund primitive is
 * *expected* to fall through the catch and surface a failed `GatewayResult`, so
 * the aggregate records `RefundFailed` and the saga carries on. Marking every
 * operation-level refusal as an invariant would turn those graceful
 * degradations into thrown exceptions mid-saga.
 *
 * So: reach for this only where the operation is unreachable except through a
 * wiring mistake, and leave the existing degradations alone until someone
 * decides, per operation, which of the two a caller should get.
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
