<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject;

use Override;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Stringable;

abstract readonly class UuidValueObject implements Stringable
{
    final protected function __construct(private UuidInterface $uuid) {}

    public static function generate(): static
    {
        return new static(Uuid::uuid7());
    }

    public static function fromString(string $aggregateRootId): static
    {
        return new static(Uuid::fromString($aggregateRootId));
    }

    /**
     * @return non-empty-string
     */
    public function toString(): string
    {
        return $this->uuid->toString();
    }

    public function equals(self $other): bool
    {
        return static::class === $other::class && $this->uuid->equals($other->uuid);
    }

    #[Override]
    public function __toString(): string
    {
        return $this->toString();
    }
}
