<?php

declare(strict_types=1);

namespace Techork\PaymentService\Revolut\Exception;

use BadMethodCallException;
use Techork\PaymentService\Common\Concern\CarriesErrorCode;
use Techork\PaymentService\Common\ValueObject\ErrorCode;
use Techork\PaymentService\Gateway\Exception\UnsupportedByGateway;

/**
 * Revolut Business is an issuing-only gateway here — it deploys virtual
 * cards but does not acquire payments (purchase / authorize / capture /
 * refund / void) and does not tokenize cards (createCard /
 * createPaymentMethod). Calling any of those is a programming error that
 * should fail loudly so the caller routes the operation to an acquiring
 * gateway (Stripe, Nuvei, ConnexPay).
 *
 * Hence {@see UnsupportedByGateway}: without it
 * {@see \Techork\PaymentService\Gateway\PaymentGatewayRouter} folds this into a
 * failed result, which becomes a recorded `PaymentIntentFailed` / `RefundFailed`
 * — the event stream then says an issuer declined a payment nobody ever sent.
 *
 * This does NOT collide with the graceful degradation that
 * {@see \Techork\PaymentService\Gateway\Exception\UnsupportedOperation} warns
 * about. That degradation is on `retryRefund()` — refunding onto an alternative
 * instrument, which Stripe and others genuinely cannot do and which is expected
 * to surface as a failed `GatewayResult` so a saga can carry on. It is step 2 of
 * `PaymentGatewayRouter::refund`. What is thrown here is step 1, and there is no
 * `retryRefund` on this class at all: a refund reaching Revolut is not a gateway
 * lacking a primitive, it is a refund routed to a gateway that never took the
 * money. Parent stays `BadMethodCallException`, so existing catches are unaffected.
 */
final class UnsupportedOperationException extends BadMethodCallException implements UnsupportedByGateway
{
    use CarriesErrorCode;

    public static function operation(string $name): self
    {
        return self::coded(ErrorCode::UnsupportedByGateway, 
            "Revolut does not support the '{$name}' operation — Revolut is an issuing-only gateway. "
            .'Route acquiring/tokenization operations to an acquiring gateway (Stripe, Nuvei, ConnexPay).'
        );
    }
}
