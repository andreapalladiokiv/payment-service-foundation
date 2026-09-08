<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Contract;

use Money\Money;

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
     * A provider answered success but named no transaction reference — nothing to capture,
     * refund or reconcile against, so it is recorded as a failure rather than as a payment
     * nobody can act on. Every driver that has to say this says it the same way; the constant
     * lived on the shared `ResultAssembler` that used to fold Omnipay responses into results,
     * and outlived it because the sentence is about the result, not about the folding.
     */
    public const string UNNAMED_SUCCESS = 'The gateway reported success without naming a transaction reference.';

    /**
     * @param array<string, mixed> $metadata        gateway-specific transaction
     *                                              attributes persisted alongside the
     *                                              reference
     * @param ?Money                $convertedAmount FX-settled amount when the gateway
     *                                              applied a currency conversion, else null
     */
    public function __construct(
        public bool $success,
        public ?string $reference,
        public ?string $message,
        public array $metadata = [],
        public ?Money $convertedAmount = null,
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
        return new self($this->success, $this->reference, $this->message, $metadata, $this->convertedAmount);
    }

    public function withConvertedAmount(?Money $convertedAmount): self
    {
        return new self($this->success, $this->reference, $this->message, $this->metadata, $convertedAmount);
    }

    /**
     * What a log line should say about this answer.
     *
     * Lives on the result rather than at each call site because the call sites got it wrong:
     * twenty-six hand-written arrays in the gateway stack
     * restated these fields per operation and drifted from each other. Subclasses override to
     * add their own signals — a challenge, AVS checks — so a richer result cannot be logged as
     * if it were a bare one.
     *
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'success' => $this->success,
            'reference' => $this->reference,
            'message' => $this->message,
        ];
    }
}
