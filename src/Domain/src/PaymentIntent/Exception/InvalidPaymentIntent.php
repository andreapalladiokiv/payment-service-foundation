<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Exception;

use EventSauce\EventSourcing\AggregateRootId;

final class InvalidPaymentIntent extends \DomainException
{
    public static function nonPositiveAmount(): self
    {
        return new self('Payment intent amount must be positive.');
    }

    public static function alreadyExists(AggregateRootId $id): self
    {
        return new self(sprintf('Payment intent %s already exists and cannot be imported over.', $id->toString()));
    }

    public static function unusablePaymentSource(): self
    {
        return new self('Payment source is not usable (expired or consumed).');
    }
}
