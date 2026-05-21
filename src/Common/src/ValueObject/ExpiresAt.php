<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

final readonly class ExpiresAt
{
    private const string FORMAT = DateTimeInterface::ATOM;

    private function __construct(
        private DateTimeImmutable $value,
    ) {}

    public static function fromDateTime(DateTimeInterface $dateTime): self
    {
        return new self(DateTimeImmutable::createFromInterface($dateTime));
    }

    public static function fromString(string $datetime): self
    {
        $parsed = DateTimeImmutable::createFromFormat(self::FORMAT, $datetime);

        if ($parsed === false) {
            throw new InvalidArgumentException("Invalid expiresAt format: [$datetime].");
        }

        return new self($parsed);
    }

    public function isExpired(DateTimeInterface $now = new DateTimeImmutable): bool
    {
        return $now >= $this->value;
    }

    public function toDateTime(): DateTimeImmutable
    {
        return $this->value;
    }

    public function toString(): string
    {
        return $this->value->format(self::FORMAT);
    }

    public function toPayload(): string
    {
        return $this->toString();
    }

    public static function fromPayload(string $payload): self
    {
        return self::fromString($payload);
    }
}
