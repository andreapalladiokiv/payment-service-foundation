<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Subscription\ValueObject;

final readonly class BillingInterval
{
    public function __construct(
        public int $every,
        public BillingPeriod $period,
    ) {
        if ($every < 1) {
            throw new \InvalidArgumentException("Billing interval must be at least 1, got {$every}.");
        }
    }

    public function periodEndFrom(\DateTimeImmutable $periodStart): \DateTimeImmutable
    {
        return $periodStart->add($this->toDateInterval());
    }

    public function toDateInterval(): \DateInterval
    {
        return match ($this->period) {
            BillingPeriod::Day => new \DateInterval("P{$this->every}D"),
            BillingPeriod::Week => new \DateInterval("P{$this->every}W"),
            BillingPeriod::Month => new \DateInterval("P{$this->every}M"),
            BillingPeriod::Year => new \DateInterval("P{$this->every}Y"),
        };
    }
}
