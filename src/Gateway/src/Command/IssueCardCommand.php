<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Command;

use Money\Money;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Gateway\ValueObject\BalanceFunded;
use Techork\PaymentService\Gateway\ValueObject\CardFunding;
use Techork\PaymentService\Gateway\ValueObject\CardLimitWindow;
use Techork\PaymentService\Gateway\ValueObject\CardSpendCategory;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\ValueObject\SaleFunded;
use Techork\PaymentService\Gateway\ValueObject\SaleFundingHint;

/**
 * Issue a virtual card, against a sale or against a balance.
 *
 * The funding model is the command's first fact, not something a driver deduces. It used to be
 * deduced — `$transactionReference` was a non-nullable top-level field, so "this card is backed by
 * a payment" was said by a string being present rather than by anyone meaning it, and a provider
 * that funds cards from a balance was handed a reference it could not use. Revolut's driver reads
 * the field zero times; that asymmetry is what this replaces.
 *
 * Two named constructors and no public one, so the two cases cannot be mixed. What distinguishes
 * them is reached through {@see $funding}, which is a {@see SaleFunded} or a {@see BalanceFunded}
 * and has no third form.
 *
 * `$limitWindow` sits out here rather than inside the balance case, beside `$spendCategory` and
 * for the same reason: both are spend controls the caller asks for, both are domain vocabulary
 * that each gateway translates into its own, and neither is a fact about where the money sits.
 * Null means "the gateway decides" — its configured period, or nothing at all on an issuer with no
 * such concept.
 */
final readonly class IssueCardCommand
{
    private function __construct(
        public GatewayId $gatewayId,
        public CardFunding $funding,
        public Money $amountLimit,
        public CardSpendCategory $spendCategory,
        public ?CardLimitWindow $limitWindow = null,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?CardBrand $cardBrand = null,
        public ?string $clientUniqueId = null,
    ) {}

    /**
     * A card drawing on money already taken.
     *
     * @param  string  $transactionReference  The acquirer's reference for the sale, resolved by the
     *                                        caller rather than looked up here.
     * @param  ?SaleFundingHint  $hint  An acquirer's second name for that same sale, when it keys
     *                                  card issuance on one — see {@see SaleFundingHint}. Opaque here; only the gateway that
     *                                  declared it reads it.
     */
    public static function saleFunded(
        GatewayId $gatewayId,
        string $transactionReference,
        Money $amountLimit,
        CardSpendCategory $spendCategory,
        ?CardLimitWindow $limitWindow = null,
        ?string $firstName = null,
        ?string $lastName = null,
        ?CardBrand $cardBrand = null,
        ?string $clientUniqueId = null,
        ?SaleFundingHint $hint = null,
    ): self {
        return new self(
            gatewayId: $gatewayId,
            funding: new SaleFunded($transactionReference, $hint),
            amountLimit: $amountLimit,
            spendCategory: $spendCategory,
            limitWindow: $limitWindow,
            firstName: $firstName,
            lastName: $lastName,
            cardBrand: $cardBrand,
            clientUniqueId: $clientUniqueId,
        );
    }

    /**
     * A card drawing on a pot the merchant funded ahead of time. No payment is named, and there is
     * no argument by which one could be.
     *
     * `$clientUniqueId` matters more here than on the sale path: with no payment id to borrow, it
     * is the card's own id, and providers that spend it as an idempotency key — Revolut takes it as
     * `request_id` — are what makes a retried issuance answer with the same card instead of a
     * second one.
     */
    public static function balanceFunded(
        GatewayId $gatewayId,
        Money $amountLimit,
        CardSpendCategory $spendCategory,
        ?CardLimitWindow $limitWindow = null,
        ?string $firstName = null,
        ?string $lastName = null,
        ?CardBrand $cardBrand = null,
        ?string $clientUniqueId = null,
    ): self {
        return new self(
            gatewayId: $gatewayId,
            funding: new BalanceFunded,
            amountLimit: $amountLimit,
            spendCategory: $spendCategory,
            limitWindow: $limitWindow,
            firstName: $firstName,
            lastName: $lastName,
            cardBrand: $cardBrand,
            clientUniqueId: $clientUniqueId,
        );
    }
}
