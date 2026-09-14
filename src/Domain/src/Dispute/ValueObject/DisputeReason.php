<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\ValueObject;

use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Domain\Dispute\Exception\InvalidDispute;

/**
 * Why the case was raised: the network, the code the network gave it, and what that code is
 * about.
 *
 * All three, and none of them derived from another at read time. The pair `(CardBrand,
 * rawCode)` is the identity — Visa's `13.1`, Mastercard's `4853` and Amex's `C08` are three
 * different codes even where they describe the same complaint, because the evidence each
 * network accepts is different and the hint logic keys off the pair. Collapsing them into one
 * enum of "not received" is what the plan forbids, and it is what would happen the moment a
 * mapper returned the category alone.
 *
 * ## The category is computed once and then frozen
 *
 * {@see fromProviderCode()} derives the category through {@see ReasonCodeMapper}; the recorded
 * value is what the event carries from then on. That is deliberate: if the mapper's table gains
 * a code next month, replaying last month's stream must reproduce the case as it was recorded,
 * not as the table reads today. Deriving on every read would silently rewrite what the
 * aggregate believed when it accepted the money, and the dispute's own history would stop being
 * a history.
 *
 * The raw code is refused when blank for the same reason a signal's key is: an empty reason is
 * a mapping bug, and one that were accepted would make every unrecognised case look alike.
 *
 * ## The brand is mandatory, and Nuvei is where that had to be arranged
 *
 * All three of the other adapters deliver a brand: ConnexPay states it as a numeric `CardBrand`
 * (1–4, refused by name outside that), Stripe states it as `payment_method_details.card.brand`
 * and refuses a delivery without one. Nuvei states **neither** — a Chargeback DMN carries
 * `Chargeback.ChargebackReason` (`"10.4 - Other Fraud-Card Absent Environment"`) and no network
 * anywhere — so the brand is read off the code's namespace at ingestion, through
 * {@see \Techork\PaymentService\Common\ValueObject\ReasonCodeNetwork}.
 *
 * It is arranged there rather than by relaxing this class, because a case whose network is unknown
 * cannot be answered at all: the evidence requirements are keyed on the `(network, code)` pair, so
 * a null brand is not an incomplete case but an unanswerable one. A delivery whose code its network
 * cannot be read from is therefore refused loudly, at the adapter, and never reaches this class.
 *
 * What the pair still guarantees, and why the network is not merely a label: the code is never
 * answered through another network's entry. Visa's `13.1`, Mastercard's `4853` and Amex's `C08` are
 * three different codes describing one complaint, and `ReasonCodeMapper` holds three separate
 * lists so that a code unknown to its own network comes back {@see ReasonCategory::Uncategorised}
 * rather than as the nearest network's meaning.
 */
final readonly class DisputeReason
{
    public function __construct(
        public CardBrand $cardBrand,
        public string $rawCode,
        public ReasonCategory $category,
    ) {
        trim($rawCode) !== '' || throw InvalidDispute::emptyReasonCode();
    }

    /**
     * The way in for an adapter: the provider's own brand and code, mapped to a category here
     * rather than at the call site, so that no adapter can record a category it invented.
     */
    public static function fromProviderCode(CardBrand $cardBrand, string $rawCode): self
    {
        return new self($cardBrand, $rawCode, ReasonCodeMapper::categorise($cardBrand, $rawCode));
    }

    /** @return array<string, string> */
    public function toPayload(): array
    {
        return [
            'card_brand' => $this->cardBrand->value,
            'raw_code' => $this->rawCode,
            'category' => $this->category->value,
        ];
    }

    /** @param array<string, string> $payload */
    public static function fromPayload(array $payload): self
    {
        return new self(
            CardBrand::from($payload['card_brand']),
            $payload['raw_code'],
            ReasonCategory::from($payload['category']),
        );
    }
}
