<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Checkout\Port;

use Techork\PaymentService\Domain\Checkout\Port\Request\CheckoutCaptureRequest;

/**
 * Driven port through which a checkout takes the money it has decided it is
 * owed: capture the authorized payment intent behind it.
 *
 * The checkout declares its own port rather than borrowing the payment intent's
 * {@see \Techork\PaymentService\Domain\PaymentIntent\Port\CapturePort}, for the
 * same reason each aggregate declares its own firewall port — it is typed to the
 * data this aggregate actually holds ({@see CheckoutCaptureRequest}: its own id,
 * the intent it was handed, its own amount), and nothing more.
 *
 * Returns nothing, and there is no outcome type to inspect, because capture has
 * no business failure mode: the money was reserved when the intent was
 * authorized. So there are exactly two answers — it returned, meaning the money
 * moved, or it threw, meaning something infrastructural went wrong (a lost
 * connection, a gateway timeout) and the caller's move is to retry the same call.
 * {@see \Techork\PaymentService\Domain\Checkout\CheckoutAggregate::pay} records
 * nothing on the way out, so a retry starts from the same place.
 *
 * Two obligations are not the host's to choose:
 *
 * 1. Capture through
 *    {@see \Techork\PaymentService\Domain\PaymentIntent\PaymentIntentAggregate::capture},
 *    not straight at the gateway. Its `Authorized`-only check is what stops one
 *    intent being consumed by two checkouts, and a port that bypasses it
 *    satisfies this interface while losing the guarantee.
 * 2. Commit the intent's `PaymentIntentCaptured` together with the checkout's
 *    `CheckoutPaymentSubmitted`, since a captured intent with no paid checkout is
 *    a charged customer holding an unpaid order.
 *
 * What this does NOT buy: serialization of two concurrent payments. Both read
 * `Authorized` from their own hydration, and the gateway call precedes the event
 * append, so the aggregate guard rejects the second set of bookkeeping while the
 * money has already left twice. Closing that is the adapter's job — the gateway's
 * own idempotency key, keyed on the intent (the convention in
 * {@see \Techork\PaymentService\Gateway\Contract\PaymentGatewayInterface} is
 * `"{paymentIntentId}:capture"`) — and it is not something this contract can
 * promise on the domain's behalf.
 */
interface CheckoutCapturePort
{
    public function capture(CheckoutCaptureRequest $request): void;
}
