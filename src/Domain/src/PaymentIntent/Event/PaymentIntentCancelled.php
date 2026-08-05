<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Event;

use EventSauce\EventSourcing\Serialization\SerializablePayload;
use Override;

final readonly class PaymentIntentCancelled implements SerializablePayload
{
    public function __construct(
        public string $reason,
    ) {}

    #[Override]
    public function toPayload(): array
    {
        return ['reason' => $this->reason];
    }

    #[Override]
    public static function fromPayload(array $payload): static
    {
        return new self($payload['reason']);
    }
}
