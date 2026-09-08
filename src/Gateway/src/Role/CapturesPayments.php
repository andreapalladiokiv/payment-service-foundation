<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Role;

use Techork\PaymentService\Gateway\Command\CaptureCommand;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Gateway\Exception\UnsupportedByGateway;

/**
 * Taking reserved money, as one thing a caller can depend on.
 *
 * A role rather than a slice of the whole gateway, because every client of this layer uses one
 * operation: `CaptureAdapter` needs `capture` and nothing else, and asking it to hold an
 * eleven-method interface tells a reader nothing about what it does.
 *
 * The same interface is implemented all the way down — the proxy that picks a provider by
 * gateway id, the decorators that log and bound failures, and the driver that talks to the
 * acquirer. That is what lets a cross-cutting concern be a decorator instead of another branch
 * inside the router: with proxy and subject speaking different languages there was no seam for
 * one to sit in, which is how the router reached 792 lines.
 *
 * Providers that have no capture do not stay silent about it: the refusal is
 * {@see UnsupportedByGateway}-marked so it propagates instead of being recorded as an acquirer
 * declining a payment nobody asked them about.
 */
interface CapturesPayments
{
    /**
     * @throws UnsupportedByGateway when the provider has no capture at all
     */
    public function capture(CaptureCommand $command): GatewayResult;
}
