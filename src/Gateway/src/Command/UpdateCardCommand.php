<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Command;

use Money\Money;
use Techork\PaymentService\Gateway\ValueObject\CardLimitWindow;
use Techork\PaymentService\Gateway\ValueObject\CardSpendCategory;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Adjust a live virtual card's limit, what it may be spent on, and how often the limit refills.
 *
 * Three fields of domain vocabulary and the gateway's own id for the card, which is what every
 * issuer names a card by. No vendor concept rides along: the window is the shared four-period
 * {@see CardLimitWindow} that each driver translates, exactly as the spend category is.
 *
 * The window is optional and stays optional, because a sale-funded card does not have one: its
 * limit is bounded by the sale once, and there is nothing to refill. Null therefore means two
 * compatible things — "this card has no window" on the sale path, and "leave the gateway's
 * configured period alone" on the balance path — and neither of them is "set it to the default",
 * which is the reading that would silently re-periodise a card issued under an older setting.
 *
 * Note what is NOT here. `UsageLimit`, `TerminateDate` and `Tolerance` are spend controls the
 * pre-bridge integration sent on ordinary sale-funded issuance and the bridge dropped; they are a
 * regression standing against already-issued cards, not lodged-card controls, and folding them in
 * here would file that regression under a feature nothing has yet exercised.
 */
final readonly class UpdateCardCommand
{
    public function __construct(
        public GatewayId $gatewayId,
        public string $cardGuid,
        public Money $amountLimit,
        public CardSpendCategory $spendCategory,
        public ?CardLimitWindow $limitWindow = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'gatewayId' => $this->gatewayId->toString(),
            'cardGuid' => $this->cardGuid,
            'amountLimit' => $this->amountLimit,
            // `->value`, not the enum. The router logged the object here and its `->value` on the
            // neighbouring operation, which is the kind of drift a derived context cannot have.
            'spendCategory' => $this->spendCategory->value,
            'limitWindow' => $this->limitWindow?->value,
        ];
    }
}
