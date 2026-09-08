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

    /**
     * Everything but the card's secrets. `cardNumber` and `cvv` are deliberately absent: the log
     * line the router wrote used to include both, and a sanitiser downstream was the only thing
     * standing between a PAN and the log file. Not emitting them is the guarantee; scrubbing them
     * afterwards is a hope.
     *
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'success' => $this->success,
            'cardGuid' => $this->cardGuid,
            'expirationDate' => $this->expirationDate,
            'status' => $this->status,
            'message' => $this->message,
        ];
    }
}
