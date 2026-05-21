<?php

declare(strict_types=1);

namespace Techork\PaymentService\Conferma\Concern;

use Techork\PaymentService\Gateway\ValueObject\CardSpendCategory;
use Techork\PaymentService\Gateway\ValueObject\PurchaseType;
use Techork\PaymentService\Gateway\ValueObject\PurchaseTypeBridge;

/**
 * Maps the platform-wide {@see CardSpendCategory} (and the legacy
 * {@see PurchaseType} via {@see PurchaseTypeBridge}) onto the Conferma
 * `spendType` body field.
 *
 * Conferma exposes only six spend buckets (Generic, Air, Accommodation,
 * Rail, Transport, ServiceFee). All `Travel*` categories map onto
 * specific Conferma spend types; non-travel categories collapse to
 * Generic — Conferma is primarily a B2B travel-VCN platform, so
 * non-travel verticals do not have a narrower bucket.
 */
final class SpendTypeMapper
{
    public const string GENERIC = 'Generic';

    public const string AIR = 'Air';

    public const string ACCOMMODATION = 'Accommodation';

    public const string RAIL = 'Rail';

    public const string TRANSPORT = 'Transport';

    /**
     * Conferma's narrow spend-fee bucket. The public readme.io docs
     * list it as `ServiceFee` (no space), but legacy production code in
     * `payment-service/app/Infrastructure/ConfermaPay/Enum/SpendTypeEnum.php`
     * sends `'Service Fee'` (with a space) and Conferma accepts it. Keep
     * the spaced form to match what's been running successfully against
     * the live API.
     */
    public const string SERVICE_FEE = 'Service Fee';

    public static function fromCategory(CardSpendCategory $category): string
    {
        return match ($category) {
            CardSpendCategory::TravelAir => self::AIR,
            CardSpendCategory::TravelLodging => self::ACCOMMODATION,
            CardSpendCategory::TravelRail => self::RAIL,
            CardSpendCategory::TravelGround,
            CardSpendCategory::TravelCruise => self::TRANSPORT,
            CardSpendCategory::ServiceFee => self::SERVICE_FEE,
            default => self::GENERIC,
        };
    }

    /**
     * Legacy bridge — kept for callers still building {@see PurchaseType}
     * before normalising to {@see CardSpendCategory}. Delegates through
     * {@see PurchaseTypeBridge::toCategory} so the mapping table lives in
     * exactly one place.
     */
    public static function fromPurchaseType(PurchaseType $type): string
    {
        return self::fromCategory(PurchaseTypeBridge::toCategory($type));
    }
}
