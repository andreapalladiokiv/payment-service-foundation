<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject\CreditCard;

use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeInterface;
use JsonSerializable;
use Override;

final readonly class Expiration implements JsonSerializable
{
    private DateTimeImmutable $expiration;

    /**
     * @throws DateMalformedStringException
     */
    public function __construct(DateTimeInterface $expiration)
    {
        $this->expiration = DateTimeImmutable::createFromInterface($expiration)
            ->modify('first day of this month midnight');
    }

    /**
     * @throws DateMalformedStringException
     */
    public static function fromMonthAndYear(int $month, int $year): self
    {
        if ($year < 100) {
            $expiration = DateTimeImmutable::createFromFormat('!my', sprintf('%02d%02d', $month, $year));
        } else {
            $expiration = DateTimeImmutable::createFromFormat('!mY', sprintf('%02d%04d', $month, $year));
        }

        return new self($expiration);
    }

    /**
     * @throws DateMalformedStringException
     */
    public function expired(): bool
    {
        return new DateTimeImmutable > $this->expiration->modify('+1 month');
    }

    public function __toString(): string
    {
        return $this->format('my');
    }

    public function format(string $format): string
    {
        return $this->expiration->format($format);
    }

    #[Override]
    public function jsonSerialize(): string
    {
        return (string) $this;
    }
}
