<?php

declare(strict_types=1);

namespace Techork\PaymentService\Conferma\Exception;

use BadMethodCallException;

/**
 * ConfermaPay is an issuing-only gateway — it cannot acquire payments
 * (purchase / authorize / capture / refund / void) and it does not
 * tokenize cards (createCard / createPaymentMethod). Calling any of
 * those operations is a programming error that should fail loudly so
 * the caller routes the operation to an acquiring gateway instead.
 */
final class UnsupportedOperationException extends BadMethodCallException
{
    public static function operation(string $name): self
    {
        return new self(
            "ConfermaPay does not support the '{$name}' operation — Conferma is an issuing-only gateway. "
            ."Route acquiring/tokenization operations to an acquiring gateway (Stripe, Nuvei, ConnexPay)."
        );
    }
}
