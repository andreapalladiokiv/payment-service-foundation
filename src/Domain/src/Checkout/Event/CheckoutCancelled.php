<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Checkout\Event;

use EventSauce\EventSourcing\Serialization\SerializablePayload;

final readonly class CheckoutCancelled implements SerializablePayload
{
    public function toPayload(): array
    {
        return [];
    }

    public static function fromPayload(array $payload): static
    {
        return new self;
    }
}
