<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Contract;

final readonly class VirtualCardResult
{
    private function __construct(
        public bool $success,
        public ?string $cardGuid,
        public ?string $cardNumber,
        public ?string $cvv,
        public ?string $expirationDate,
        public ?string $status,
        public ?string $message,
    ) {}

    public static function succeeded(
        string $cardGuid,
        ?string $cardNumber = null,
        ?string $cvv = null,
        ?string $expirationDate = null,
        ?string $status = null,
    ): self {
        return new self(true, $cardGuid, $cardNumber, $cvv, $expirationDate, $status, null);
    }

    public static function failed(string $message): self
    {
        return new self(false, null, null, null, null, null, $message);
    }
}
