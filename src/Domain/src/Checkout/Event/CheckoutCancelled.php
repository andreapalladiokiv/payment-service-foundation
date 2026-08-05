<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Checkout\Event;

use EventSauce\EventSourcing\Serialization\SerializablePayload;
use Override;

final readonly class CheckoutCancelled implements SerializablePayload
{
    #[Override]
    public function toPayload(): array
    {
        return [];
    }

    #[Override]
    public static function fromPayload(array $payload): static
    {
        return new self;
    }
}
