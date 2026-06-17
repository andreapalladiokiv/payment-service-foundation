<?php

declare(strict_types=1);

namespace Techork\PaymentService\Revolut\Concern;

use Techork\PaymentService\Gateway\ValueObject\CardSpendCategory;

/**
 * Maps the platform-wide {@see CardSpendCategory} onto a Revolut card
 * merchant-category control (the `categories` allow-list on create /
 * update card).
 *
 * Revolut's category controls are coarse merchant buckets (the create-card
 * docs sample uses `groceries`, `restaurants`; `travel`, `transport` and
 * `services` are the buckets relevant to a B2B travel-VCN issuer). A
 * category Revolut does not recognise rejects the whole request, so the
 * mapper is deliberately conservative: only the buckets we are confident
 * about are emitted, and anything without a safe counterpart returns
 * null — the request then omits `categories` entirely (no merchant
 * restriction) rather than risking a 400.
 */
final class MerchantCategoryMapper
{
    public const string TRAVEL = 'travel';

    public const string TRANSPORT = 'transport';

    public const string SERVICES = 'services';

    public const string RESTAURANTS = 'restaurants';

    public static function fromCategory(CardSpendCategory $category): ?string
    {
        return match ($category) {
            CardSpendCategory::TravelAir,
            CardSpendCategory::TravelLodging,
            CardSpendCategory::TravelCruise,
            CardSpendCategory::TravelGeneric => self::TRAVEL,
            CardSpendCategory::TravelRail,
            CardSpendCategory::TravelGround => self::TRANSPORT,
            CardSpendCategory::Restaurants => self::RESTAURANTS,
            CardSpendCategory::ServiceFee => self::SERVICES,
            default => null,
        };
    }
}
