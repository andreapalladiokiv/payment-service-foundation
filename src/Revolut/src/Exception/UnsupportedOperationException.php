<?php

declare(strict_types=1);

namespace Techork\PaymentService\Revolut\Exception;

use BadMethodCallException;

/**
 * Revolut Business is an issuing-only gateway here — it deploys virtual
 * cards but does not acquire payments (purchase / authorize / capture /
 * refund / void) and does not tokenize cards (createCard /
 * createPaymentMethod). Calling any of those is a programming error that
 * should fail loudly so the caller routes the operation to an acquiring
 * gateway (Stripe, Nuvei, ConnexPay).
 */
final class UnsupportedOperationException extends BadMethodCallException
{
    public static function operation(string $name): self
    {
        return new self(
            "Revolut does not support the '{$name}' operation — Revolut is an issuing-only gateway. "
            .'Route acquiring/tokenization operations to an acquiring gateway (Stripe, Nuvei, ConnexPay).'
        );
    }
}
