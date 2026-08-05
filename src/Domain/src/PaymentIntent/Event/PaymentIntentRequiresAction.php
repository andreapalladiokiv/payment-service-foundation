<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Event;

use EventSauce\EventSourcing\Serialization\SerializablePayload;
use Money\Currency;
use Money\Money;
use Override;
use Techork\PaymentService\Common\Contract\Challenge;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\MerchantDescriptor;
use Techork\PaymentService\Common\ValueObject\PaymentInstrumentFactory;
use Techork\PaymentService\Domain\PaymentIntent\CaptureMethod;
use Techork\PaymentService\Domain\PaymentIntent\ChallengeArraySerializer;
use Techork\PaymentService\Common\ValueObject\PaymentInitiation;

final readonly class PaymentIntentRequiresAction implements SerializablePayload
{
    public function __construct(
        public Money $amount,
        public PaymentInstrument $instrument,
        public CaptureMethod $captureMethod,
        public BillingAddress $billingAddress,
        /** @var array<string, mixed> */
        public array $metadata,
        public MerchantDescriptor $merchantDescriptor,
        public string $description,
        /**
         * Null when the payment must be authenticated and nobody has raised a challenge yet —
         * a firewall that demanded a step-up with no challenge integration behind it. A
         * {@see Challenge} is evidence that a handoff to an external system has already
         * happened and carries what the client needs to render it, so there is nothing
         * truthful to put here until the ACS has actually been asked.
         */
        public ?Challenge $challenge,
        public PaymentInitiation $initiation = PaymentInitiation::CardholderInitiated,
    ) {}

    #[Override]
    public function toPayload(): array
    {
        return [
            'amount' => $this->amount->getAmount(),
            'currency' => $this->amount->getCurrency()->getCode(),
            'instrument' => $this->instrument->toPayload(),
            'capture_method' => $this->captureMethod->value,
            'billing_address' => $this->billingAddress->toArray(),
            'metadata' => $this->metadata,
            'merchant_descriptor' => (string) $this->merchantDescriptor,
            'description' => $this->description,
            'challenge' => $this->challenge === null ? null : ChallengeArraySerializer::toArray($this->challenge),
            'initiation' => $this->initiation->value,
        ];
    }

    #[Override]
    public static function fromPayload(array $payload): static
    {
        return new self(
            new Money($payload['amount'], new Currency($payload['currency'])),
            PaymentInstrumentFactory::fromPayload($payload['instrument']),
            CaptureMethod::from($payload['capture_method']),
            BillingAddress::fromArray($payload['billing_address']),
            $payload['metadata'] ?? [],
            new MerchantDescriptor($payload['merchant_descriptor']),
            $payload['description'],
            isset($payload['challenge']) ? ChallengeArraySerializer::fromArray($payload['challenge']) : null,
            PaymentInitiation::from($payload['initiation'] ?? PaymentInitiation::CardholderInitiated->value),
        );
    }
}
