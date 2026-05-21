<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject\CreditCard;

use JsonSerializable;
use Override;
use Stringable;

final readonly class Holder implements JsonSerializable, Stringable
{
    public function __construct(private string $name) {}

    #[Override]
    public function __toString(): string
    {
        return $this->name;
    }

    #[Override]
    public function jsonSerialize(): string
    {
        return (string) $this;
    }
}
