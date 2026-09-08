<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Command;

use Money\Money;
use Techork\PaymentService\Gateway\ValueObject\CardSpendCategory;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Adjust a live virtual card's limit or what it may be spent on.
 */
final readonly class UpdateCardCommand
{
    public function __construct(
        public GatewayId $gatewayId,
        public string $cardGuid,
        public Money $amountLimit,
        public CardSpendCategory $spendCategory,
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
        ];
    }
}
