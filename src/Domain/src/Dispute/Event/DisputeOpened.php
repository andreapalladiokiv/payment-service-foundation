<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\Event;

use DateTimeImmutable;
use DateTimeInterface;
use EventSauce\EventSourcing\Serialization\SerializablePayload;
use Money\Currency;
use Money\Money;
use Override;
use Techork\PaymentService\Domain\Dispute\DisputeStage;
use Techork\PaymentService\Domain\Dispute\DisputeStatus;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeReason;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;

/**
 * The first delivery of an external signal about a case, and everything that signal carried.
 *
 * The whole opening state is on this one event rather than spread over a status change, a stage
 * change and a deadline change, because a case is *known* the instant it is first described: a
 * stream that opened with a bare id and then walked through NEEDS_RESPONSE, CHARGEBACK and a
 * deadline would have four events where the provider said one thing, and every replay would pass
 * through states no provider ever reported.
 *
 * The stage is here rather than left to a following stage change, and that is what makes the case's
 * own history a projection of the stream: a case that never escalated has this event and no
 * {@see DisputeStageChanged} at all, so a reader that counted stage changes would report an empty
 * history for it. `providerCode` rides beside the stage for the reason {@see DisputeStageChanged}
 * sets out.
 *
 * `observedAt` is when we received it, not when the case was raised at the network. The
 * difference is not decoration: every other fact on this aggregate carries its own provider date
 * where the provider states one — {@see DisputeStageChanged::$occurredAt}, and the fees'
 * `chargedAt` — and this is the only one with nothing but our receipt to go on.
 *
 * `signalKey` is on the event because it is on every event: it is what makes "the same delivery
 * arrived twice" answerable from the stream alone, without a side table of seen keys. See
 * {@see \Techork\PaymentService\Domain\Dispute\ValueObject\DisputeSignal::$providerEventKey}.
 */
final readonly class DisputeOpened implements SerializablePayload
{
    public function __construct(
        public PaymentIntentId $paymentIntentId,
        public DisputeStage $stage,
        public DisputeStatus $status,
        public DisputeReason $reason,
        public Money $disputedAmount,
        public ?DateTimeImmutable $deadlineAt,
        public ?string $providerCode,
        public string $signalKey,
        public DateTimeImmutable $observedAt,
    ) {}

    #[Override]
    public function toPayload(): array
    {
        return [
            'payment_intent_id' => $this->paymentIntentId->toString(),
            'stage' => $this->stage->value,
            'status' => $this->status->value,
            'provider_code' => $this->providerCode,
            'reason' => $this->reason->toPayload(),
            'disputed_amount' => $this->disputedAmount->getAmount(),
            'disputed_currency' => $this->disputedAmount->getCurrency()->getCode(),
            'deadline_at' => $this->deadlineAt?->format(DateTimeInterface::ATOM),
            'signal_key' => $this->signalKey,
            'observed_at' => $this->observedAt->format(DateTimeInterface::ATOM),
        ];
    }

    #[Override]
    public static function fromPayload(array $payload): static
    {
        return new self(
            PaymentIntentId::fromString($payload['payment_intent_id']),
            DisputeStage::from($payload['stage']),
            DisputeStatus::from($payload['status']),
            DisputeReason::fromPayload($payload['reason']),
            new Money($payload['disputed_amount'], new Currency($payload['disputed_currency'])),
            $payload['deadline_at'] === null ? null : new DateTimeImmutable($payload['deadline_at']),
            $payload['provider_code'],
            $payload['signal_key'],
            new DateTimeImmutable($payload['observed_at']),
        );
    }
}
