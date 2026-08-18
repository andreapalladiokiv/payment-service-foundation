<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Subscription\Event;

use DateTimeImmutable;
use DateTimeInterface;
use EventSauce\EventSourcing\Serialization\SerializablePayload;
use Override;
use RuntimeException;

final readonly class SubscriptionCancelled implements SerializablePayload
{
    /**
     * @param  DateTimeImmutable  $effectiveAt  When the subscription is over.
     *
     * The end of the current period when the subscriber is owed the rest of what they paid
     * for, the moment of cancellation when they are not. Which of the two it is depends on
     * facts only {@see \Techork\PaymentService\Domain\Subscription\SubscriptionAggregate::cancel}
     * holds together — whether a period exists, and whether the caller says it was ever
     * paid for — so it is decided once, there, and written down here.
     *
     * It is on the event because the aggregate is not what anyone downstream reads. This
     * used to carry the reason alone, and the difference between "over now" and "over at
     * the end of the month" existed only as a computation inside the aggregate; every
     * projection had to re-derive it from the period columns, which is a second copy of a
     * rule and had already drifted from the first.
     *
     * A reader still has to compare it against the clock: a cancellation scheduled for the
     * end of a period produces no further event when that moment arrives, because nothing
     * happens then — no money moves and nobody is told. Storing this instant and comparing
     * it is the whole contract. A projection that copies a status at write time and never
     * looks again will say `active` forever.
     */
    public function __construct(
        public string $reason,
        public DateTimeImmutable $effectiveAt,
    ) {}

    #[Override]
    public function toPayload(): array
    {
        return [
            'reason' => $this->reason,
            'effective_at' => $this->effectiveAt->format(DateTimeInterface::RFC3339_EXTENDED),
        ];
    }

    #[Override]
    public static function fromPayload(array $payload): static
    {
        // Refused rather than defaulted, for the reason the checkout's snapshot refuses an
        // unreadable expiry: a cancellation whose moment cannot be read is not a
        // cancellation that happens now, and guessing either way mis-states a subscription
        // that someone is still being billed for — or is not.
        $effectiveAt = DateTimeImmutable::createFromFormat(DateTimeInterface::RFC3339_EXTENDED, $payload['effective_at'] ?? '')
            ?: throw new RuntimeException(
                sprintf('SubscriptionCancelled carries an unreadable effective_at: %s', var_export($payload['effective_at'] ?? null, true)),
            );

        return new self($payload['reason'], $effectiveAt);
    }
}
