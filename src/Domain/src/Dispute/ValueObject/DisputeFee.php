<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\ValueObject;

use DateTimeImmutable;
use DateTimeInterface;
use Money\Currency;
use Money\Money;
use Override;
use Techork\PaymentService\Domain\Dispute\Exception\InvalidDispute;

/**
 * One fee the provider charged, or returned, in connection with this case.
 *
 * A value in a list rather than a field on the aggregate, because one dispute collects several:
 * see {@see FeeType} for the concrete shapes at Stripe and ConnexPay. `amount` is positive as
 * charged — direction belongs to the type and to A4, and a signed amount here would put the
 * same decision in two places.
 *
 * The provider states these fees, not us. Stripe's settlement data carries both, and Nuvei's
 * DMN carries a fee amount; **ConnexPay's case payload carries none** — its four fees are known
 * from the operational rules, and `NetPosition` is the source of truth for money. So nothing
 * here derives a fee from a net position: the aggregate records what it is told, and where a
 * provider states nothing A4 derives it from `NetPosition` and the settlement data instead of
 * finding an invented number on this aggregate.
 *
 * Which is also why re-observing the same fee emits nothing: a poller re-reading a case, and a
 * webhook redelivering one, both hand the aggregate the same triple. Identity is
 * `(type, amount, chargedAt)` and all three parts are required — the same $20 charged twice on
 * two different days is two fees, and the same $20 at the same instant is the same fee told
 * twice.
 */
final readonly class DisputeFee
{
    public function __construct(
        public FeeType $type,
        public Money $amount,
        public DateTimeImmutable $chargedAt,
    ) {
        $amount->isPositive() || throw InvalidDispute::nonPositiveFee($amount);
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type
            && $this->amount->equals($other->amount)
            && $this->chargedAt->format(DateTimeInterface::ATOM) === $other->chargedAt->format(DateTimeInterface::ATOM);
    }

    /** @return array<string, string> */
    public function toPayload(): array
    {
        return [
            'type' => $this->type->value,
            'amount' => $this->amount->getAmount(),
            'currency' => $this->amount->getCurrency()->getCode(),
            'charged_at' => $this->chargedAt->format(DateTimeInterface::ATOM),
        ];
    }

    /** @param array<string, string> $payload */
    public static function fromPayload(array $payload): self
    {
        // Both annotations describe what {@see self::toPayload()} writes and nothing more: the
        // amount is `Money::getAmount()` — an integer rendered as a numeric string — and the
        // currency code comes from `Currency::getCode()`, which cannot be empty. Psalm cannot see
        // that through a plain `array<string, string>`, and Money will not accept a `string` it
        // cannot prove is numeric or a code it cannot prove is non-empty.
        /** @var numeric-string $amount */
        $amount = $payload['amount'];
        /** @var non-empty-string $currency */
        $currency = $payload['currency'];

        return new self(
            FeeType::from($payload['type']),
            new Money($amount, new Currency($currency)),
            new DateTimeImmutable($payload['charged_at']),
        );
    }
}
