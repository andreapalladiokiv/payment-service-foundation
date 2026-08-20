<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Customer\Event;

use EventSauce\EventSourcing\Serialization\SerializablePayload;
use Override;
use Techork\PaymentService\Common\ValueObject\BillingAddress;

final readonly class CustomerAddressChanged implements SerializablePayload
{
    public function __construct(
        public BillingAddress $address,
    ) {}

    #[Override]
    public function toPayload(): array
    {
        return ['address' => $this->address->toArray()];
    }

    #[Override]
    public static function fromPayload(array $payload): static
    {
        return new self(BillingAddress::fromArray($payload['address']));
    }
}
