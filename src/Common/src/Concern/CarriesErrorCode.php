<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\Concern;

use Techork\PaymentService\Common\Contract\CodedError;
use Techork\PaymentService\Common\ValueObject\ErrorCode;

/**
 * The {@see CodedError} half that every implementation would otherwise write identically.
 *
 * {@see coded()} exists so a named constructor states its code in the same expression that states
 * its message, and cannot state one without the other. The alternative — a property set after
 * construction, or a code defaulted per class — is how a new named constructor gets added carrying
 * whatever code its neighbours happened to use.
 *
 * Per constructor rather than per class, because one class often refuses for more than one
 * reason: a capture rejected because the payment is in the wrong state and a capture rejected
 * because the capture method settles inline are the same exception and different answers.
 */
trait CarriesErrorCode
{
    /**
     * Deliberately without a default. These classes inherit a public constructor and PHP will not
     * let it be hidden, so `new SomeRefusal('...')` stays reachable — and a default here would
     * answer that call with a code nobody chose, which is worse than not answering. Uninitialised,
     * it raises `Error: Typed property … must not be accessed before initialization`, which is
     * loud, immediate, and names the property.
     */
    private ErrorCode $errorCode;

    public function errorCode(): ErrorCode
    {
        return $this->errorCode;
    }

    protected static function coded(ErrorCode $code, string $message): static
    {
        $refusal = new static($message);
        $refusal->errorCode = $code;

        return $refusal;
    }
}
