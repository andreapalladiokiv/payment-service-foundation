<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Command;

use Money\Money;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Gateway\ValueObject\CardSpendCategory;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Issue a virtual card against money already taken.
 *
 * `$transactionReference` and `$incomingTransactionCode` are resolved by the caller, not looked up
 * here. The router used to read both out of the transaction repository mid-operation, which is the
 * same split of one identity's lifecycle across two layers that the payment operations already
 * moved away from: whoever persists a reference is who should resolve it.
 */
final readonly class IssueCardCommand
{
    /**
     * @param  ?string  $incomingTransactionCode  The code persisted at sale or capture time. Passing
     *   it spares the provider a Search/Sales round trip whose guid filters ConnexPay silently
     *   ignores.
     */
    public function __construct(
        public GatewayId $gatewayId,
        public string $transactionReference,
        public Money $amountLimit,
        public CardSpendCategory $spendCategory,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?CardBrand $cardBrand = null,
        public ?string $clientUniqueId = null,
        public ?string $incomingTransactionCode = null,
    ) {}


    /**
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'gatewayId' => $this->gatewayId->toString(),
            'transactionReference' => $this->transactionReference,
            'amountLimit' => $this->amountLimit,
            'spendCategory' => $this->spendCategory->value,
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'cardBrand' => $this->cardBrand?->value,
            'clientUniqueId' => $this->clientUniqueId,
        ];
    }
}
