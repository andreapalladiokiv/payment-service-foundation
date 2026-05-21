<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Subscription\ValueObject;

use DateInterval;
use Money\Currency;
use Money\Money;

final readonly class SubscriptionPlan
{
    public function __construct(
        public Money $amount,
        public BillingInterval $interval,
        public ?DateInterval $trialPeriod = null,
    ) {}

    public function toArray(): array
    {
        return [
            'amount' => $this->amount->getAmount(),
            'currency' => $this->amount->getCurrency()->getCode(),
            'interval_every' => $this->interval->every,
            'interval_period' => $this->interval->period->value,
            'trial_period' => $this->trialPeriod?->format('P%yY%mM%dDT%hH%iM%sS'),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            new Money($data['amount'], new Currency($data['currency'])),
            new BillingInterval($data['interval_every'], BillingPeriod::from($data['interval_period'])),
            isset($data['trial_period']) ? new DateInterval($data['trial_period']) : null,
        );
    }
}
