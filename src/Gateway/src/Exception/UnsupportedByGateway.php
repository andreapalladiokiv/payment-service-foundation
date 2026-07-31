<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Exception;

use Throwable;

/**
 * Marker for "this gateway structurally cannot do what was asked" — a wiring
 * error, not a payment outcome. Routing a `HostedPayment` to an acquirer with
 * no hosted product, or an acquiring call to an issuing-only gateway, is a
 * mistake in how the caller selected the gateway; no retry, no alternative
 * instrument and no cardholder action can make it succeed.
 *
 * {@see \Techork\PaymentService\Gateway\PaymentGatewayRouter} rethrows anything
 * carrying this marker instead of folding it into a failed result. That
 * distinction matters downstream: every other failure the router catches
 * becomes `AuthorizationResult::failed()` →
 * `\Techork\PaymentService\Domain\PaymentIntent\Port\GatewayDeclinedException`
 * → a recorded `PaymentIntentFailed`, i.e. it enters the event stream as an
 * acquirer decline. An invariant violation recorded as a decline is a lie about
 * the payment: it tells operators the issuer said no when in fact we never
 * asked anyone.
 *
 * This is deliberately a marker interface rather than a base class, so an
 * existing gateway exception can adopt it without changing the parent its
 * callers already catch.
 */
interface UnsupportedByGateway extends Throwable {}
