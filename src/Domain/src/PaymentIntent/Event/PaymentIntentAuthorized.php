<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Event;

use EventSauce\EventSourcing\Serialization\SerializablePayload;
use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\Contract\ChallengeResult;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\MerchantDescriptor;
use Techork\PaymentService\Common\ValueObject\PaymentInstrumentFactory;
use Techork\PaymentService\Domain\PaymentIntent\CaptureMethod;
use Techork\PaymentService\Domain\PaymentIntent\ChallengeResultArraySerializer;
use Techork\PaymentService\Domain\PaymentIntent\PaymentInitiation;

final readonly class PaymentIntentAuthorized implements SerializablePayload
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
        public ?ChallengeResult $challengeResult = null,
        public PaymentInitiation $initiation = PaymentInitiation::CardholderInitiated,
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
            'merchant_descriptor' => (string) $this->merchantDescriptor,
            'description' => $this->description,
            'challenge_result' => $this->challengeResult === null ? null : ChallengeResultArraySerializer::toArray($this->challengeResult),
            'initiation' => $this->initiation->value,
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
            new MerchantDescriptor($payload['merchant_descriptor']),
            $payload['description'],
            isset($payload['challenge_result']) ? ChallengeResultArraySerializer::fromArray($payload['challenge_result']) : null,
            PaymentInitiation::from($payload['initiation'] ?? PaymentInitiation::CardholderInitiated->value),
        );
    }
}
