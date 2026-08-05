<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject\CreditCard;

use DateMalformedStringException;
use DateTimeImmutable;
use InvalidArgumentException;
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

        // createFromFormat yields false for a value the format cannot read. Reaching the
        // constructor with it would surface as a TypeError naming DateTimeInterface, which
        // says nothing about the month and year that produced it.
        return new self($expiration ?: throw new InvalidArgumentException(
            sprintf('Card expiration %02d/%d is not a readable date.', $month, $year),
        ));
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
