<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject;

use DateMalformedStringException;
use InvalidArgumentException;
use libphonenumber\NumberParseException;
use Techork\PaymentService\Common\Contract\PaymentInstrument;

final class PaymentInstrumentFactory
{
    /**
     * @throws NumberParseException
     * @throws DateMalformedStringException
     */
    public static function fromPayload(array $payload): PaymentInstrument
    {
        return match ($payload['type']) {
            CreditCard::type() => CreditCard::fromPayload($payload),
            Cash::type() => Cash::fromPayload($payload),
            Token::type() => Token::fromPayload($payload),
            PaymentMethod::type() => PaymentMethod::fromPayload($payload),
            HostedPayment::type() => HostedPayment::fromPayload($payload),
            default => throw new InvalidArgumentException("Unknown instrument type: {$payload['type']}"),
        };
    }
}
