<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\Event;

use DateTimeImmutable;
use DateTimeInterface;
use EventSauce\EventSourcing\Serialization\SerializablePayload;
use Override;
use Techork\PaymentService\Domain\Dispute\DisputeStage;

/**
 * The case moved a stage: inquiry to chargeback, chargeback to pre-arbitration.
 *
 * `providerCode` is the provider's own cycle code, verbatim and unmapped, and it is the reason
 * this event is not reducible to a pair of stages. ConnexPay's `CaseType` folds two and three
 * values onto one stage — a first chargeback and a second chargeback are both `CHARGEBACK` — so
 * the stages alone would record a second cycle as a no-op, and the aggregate would report a case
 * escalating when it had actually re-escalated.
 *
 * The code rides on the event rather than being dropped once the stage has been applied, and that
 * is what makes the case's own history a projection: a reader rebuilding it from the stream tells
 * the two visits to `CHARGEBACK` apart by these codes, and nothing else in the stream does.
 */
final readonly class DisputeStageChanged implements SerializablePayload
{
    public function __construct(
        public DisputeStage $from,
        public DisputeStage $to,
        public ?string $providerCode,
        public string $signalKey,
        public DateTimeImmutable $occurredAt,
    ) {}

    #[Override]
    public function toPayload(): array
    {
        return [
            'from_stage' => $this->from->value,
            'to_stage' => $this->to->value,
            'provider_code' => $this->providerCode,
            'signal_key' => $this->signalKey,
            'occurred_at' => $this->occurredAt->format(DateTimeInterface::ATOM),
        ];
    }

    #[Override]
    public static function fromPayload(array $payload): static
    {
        return new self(
            DisputeStage::from($payload['from_stage']),
            DisputeStage::from($payload['to_stage']),
            $payload['provider_code'],
            $payload['signal_key'],
            new DateTimeImmutable($payload['occurred_at']),
        );
    }
}
