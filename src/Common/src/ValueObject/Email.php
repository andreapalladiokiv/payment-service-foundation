<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject;

use JsonSerializable;
use Override;
use RuntimeException;
use Stringable;

final readonly class Email implements JsonSerializable, Stringable
{
    public function __construct(private string $email)
    {
        $this->validate();
    }

    private function validate(): void
    {
        filter_var($this->email, FILTER_VALIDATE_EMAIL) !== false || throw new RuntimeException('Invalid email');
    }

    #[Override]
    public function __toString(): string
    {
        return $this->email;
    }

    #[Override]
    public function jsonSerialize(): string
    {
        return (string) $this;
    }
}
