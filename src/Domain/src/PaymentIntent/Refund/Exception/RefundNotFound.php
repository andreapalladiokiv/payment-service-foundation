<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Refund\Exception;

use Techork\PaymentService\Domain\PaymentIntent\Refund\ValueObject\RefundId;

final class RefundNotFound extends \DomainException
{
    public static function withId(RefundId $id): self
    {
        return new self("Refund [{$id->toString()}] not found on this payment intent.");
    }
}
