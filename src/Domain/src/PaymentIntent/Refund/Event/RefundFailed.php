<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Refund\Event;

use EventSauce\EventSourcing\Serialization\SerializablePayload;
use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\PaymentInstrumentFactory;
use Techork\PaymentService\Domain\PaymentIntent\Refund\ValueObject\RefundId;

final readonly class RefundFailed implements SerializablePayload
{
    public function __construct(
        public RefundId $refundId,
        public Money $amount,
        public string $reason,
        public ?PaymentInstrument $retryInstrument = null,
    ) {}

    public function toPayload(): array
    {
        return [
            'refund_id' => $this->refundId->toString(),
            'amount' => $this->amount->getAmount(),
            'currency' => $this->amount->getCurrency()->getCode(),
            'reason' => $this->reason,
            'retry_instrument' => $this->retryInstrument?->toPayload(),
        ];
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            RefundId::fromString($payload['refund_id']),
            new Money($payload['amount'], new Currency($payload['currency'])),
            $payload['reason'],
            isset($payload['retry_instrument']) ? PaymentInstrumentFactory::fromPayload($payload['retry_instrument']) : null,
        );
    }
}
