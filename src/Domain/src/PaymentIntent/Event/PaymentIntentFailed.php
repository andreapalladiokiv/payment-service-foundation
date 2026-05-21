<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Event;

use EventSauce\EventSourcing\Serialization\SerializablePayload;
use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\Contract\ChallengeResult;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\PaymentInstrumentFactory;
use Techork\PaymentService\Domain\PaymentIntent\CaptureMethod;
use Techork\PaymentService\Domain\PaymentIntent\ChallengeResultArraySerializer;

final readonly class PaymentIntentFailed implements SerializablePayload
{
    public function __construct(
        public Money $amount,
        public PaymentInstrument $instrument,
        public CaptureMethod $captureMethod,
        public BillingAddress $billingAddress,
        /** @var array<string, mixed> */
        public array $metadata,
        public string $reason,
        public ?ChallengeResult $challengeResult = null,
    ) {}

    public function toPayload(): array
    {
        return [
            'amount' => $this->amount->getAmount(),
            'currency' => $this->amount->getCurrency()->getCode(),
            'instrument' => $this->instrument->toPayload(),
            'capture_method' => $this->captureMethod->value,
            'billing_address' => $this->billingAddress->toArray(),
            'metadata' => $this->metadata,
            'reason' => $this->reason,
            'challenge_result' => $this->challengeResult === null ? null : ChallengeResultArraySerializer::toArray($this->challengeResult),
        ];
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            new Money($payload['amount'], new Currency($payload['currency'])),
            PaymentInstrumentFactory::fromPayload($payload['instrument']),
            CaptureMethod::from($payload['capture_method']),
            BillingAddress::fromArray($payload['billing_address']),
            $payload['metadata'] ?? [],
            $payload['reason'],
            isset($payload['challenge_result']) ? ChallengeResultArraySerializer::fromArray($payload['challenge_result']) : null,
        );
    }
}
