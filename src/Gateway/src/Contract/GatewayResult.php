<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Contract;

/**
 * Lean transactional outcome of a gateway interaction. Two terminal shapes:
 *  - success: operation completed, {@see $reference} set.
 *  - failed:  terminal failure with human-readable {@see $message}.
 *
 * Used directly for capture / refund / cancel / terminate, where no further
 * signals come back from the gateway. Operations that DO carry extra signals
 * (challenge for 3DS / hosted, customer references, AVS / CVC checks) return
 * subclasses that extend this base — never bolted onto this type.
 *
 * @see AuthorizationResult — for authorize / charge (challenge + checks).
 * @see RegistrationResult  — for tokenize / createPaymentMethod
 *                            (customerReference + checks).
 */
readonly class GatewayResult
{
    /**
     * @param array<string, mixed> $metadata gateway-specific transaction
     *                                       attributes persisted alongside the
     *                                       reference (see {@see TransactionMetadataProvider})
     */
    public function __construct(
        public bool $success,
        public ?string $reference,
        public ?string $message,
        public array $metadata = [],
    ) {}

    public static function succeeded(string $reference): static
    {
        return new static(true, $reference, null);
    }

    public static function failed(string $message): static
    {
        return new static(false, null, $message);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function withMetadata(array $metadata): self
    {
        return new self($this->success, $this->reference, $this->message, $metadata);
    }
}
