<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject;

use Override;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\Contract\PaymentInstrumentVisitor;

final readonly class Cash implements PaymentInstrument
{
    private const string TYPE = 'cash';

    #[Override]
    public static function type(): string
    {
        return self::TYPE;
    }

    #[Override]
    public function isValid(): bool
    {
        return true;
    }

    #[Override]
    public function accept(PaymentInstrumentVisitor $visitor): mixed
    {
        return $visitor->visitCash($this);
    }

    #[Override]
    public function toPayload(): array
    {
        return ['type' => self::TYPE];
    }

    #[Override]
    public static function fromPayload(array $payload): self
    {
        return new self;
    }
}
