<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Refund\Event;

use EventSauce\EventSourcing\Serialization\SerializablePayload;
use Money\Currency;
use Money\Money;
use Override;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\ErrorCode;
use Techork\PaymentService\Common\ValueObject\PaymentInstrumentFactory;
use Techork\PaymentService\Domain\PaymentIntent\Refund\ValueObject\RefundId;

final readonly class RefundFailed implements SerializablePayload
{
    public function __construct(
        public RefundId $refundId,
        public Money $amount,
        /**
         * The sentence an operator reads — here always the acquirer's own words, since a refund
         * only ever fails by being declined. Never parsed; {@see $code} is what a program reads.
         */
        public string $reason,
        public ErrorCode $code,
        public ?PaymentInstrument $retryInstrument = null,
    ) {}

    #[Override]
    public function toPayload(): array
    {
        return [
            'refund_id' => $this->refundId->toString(),
            'amount' => $this->amount->getAmount(),
            'currency' => $this->amount->getCurrency()->getCode(),
            'reason' => $this->reason,
            'code' => $this->code->value,
            'retry_instrument' => $this->retryInstrument?->toPayload(),
        ];
    }

    #[Override]
    public static function fromPayload(array $payload): static
    {
        return new self(
            RefundId::fromString($payload['refund_id']),
            new Money($payload['amount'], new Currency($payload['currency'])),
            $payload['reason'],
            // Rows written before the field existed say so rather than being handed a
            // classification nobody made at the time.
            ErrorCode::tryFrom((string) ($payload['code'] ?? '')) ?? ErrorCode::Unspecified,
            isset($payload['retry_instrument']) ? PaymentInstrumentFactory::fromPayload($payload['retry_instrument']) : null,
        );
    }
}
