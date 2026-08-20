<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Customer\Event;

use EventSauce\EventSourcing\Serialization\SerializablePayload;
use Override;

/**
 * The customer's identity has been erased at their request.
 *
 * It carries a reason and not the identity it replaced — writing that here would put the very
 * data being erased into an append-only stream, which is the one place it cannot be taken back
 * out of. The erasure itself happens through the `#[Pii]` store: the values leave, the stubs
 * and the hashes stay, and the stream still replays.
 */
final readonly class CustomerForgotten implements SerializablePayload
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
