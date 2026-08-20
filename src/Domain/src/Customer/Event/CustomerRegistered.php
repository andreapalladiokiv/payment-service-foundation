<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Customer\Event;

use EventSauce\EventSourcing\Serialization\SerializablePayload;
use Override;
use Techork\PaymentService\Common\ValueObject\CustomerIdentity;

final readonly class CustomerRegistered implements SerializablePayload
{
    public function __construct(
        public CustomerIdentity $identity,
    ) {}

    #[Override]
    public function toPayload(): array
    {
        return ['identity' => $this->identity->toArray()];
    }

    #[Override]
    public static function fromPayload(array $payload): static
    {
        return new self(CustomerIdentity::fromArray($payload['identity']));
    }
}
