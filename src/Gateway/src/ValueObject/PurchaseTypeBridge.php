<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\ValueObject;

/**
 * Conversion bridge between the ConnexPay-native {@see PurchaseType} and the
 * domain {@see CardSpendCategory}.
 *
 * Used while existing call sites still build {@see PurchaseType} —
 * gateway-package mappers can normalize through this bridge to
 * {@see CardSpendCategory}.
 *
 * The two enums stand one-to-one apart from three deliberate seams:
 *
 *  - ConnexPay 31 (`InsuranceUnderwritingAndPremiums`) and 32
 *    (`InsuranceAndRealEstate`) both fold into {@see CardSpendCategory::Insurance};
 *    we never distinguished them. Round-tripping 32 yields 31.
 *  - {@see CardSpendCategory::TravelRail} has no ConnexPay code and widens to
 *    06 `Travel`.
 *  - {@see CardSpendCategory::ServiceFee} has no ConnexPay code and widens to
 *    22 `MiscAndBusiness`, which is where {@see CardSpendCategory::BusinessServices}
 *    lands too.
 *
 * Both fallbacks widen the restriction rather than redirecting it, so the
 * card still authorises where the domain intended.
 */
final class PurchaseTypeBridge
{
    public static function toCategory(PurchaseType $purchaseType): CardSpendCategory
    {
        return match ($purchaseType) {
            PurchaseType::Airline => CardSpendCategory::TravelAir,
            PurchaseType::HotelAndResort => CardSpendCategory::TravelLodging,
            PurchaseType::CarRental => CardSpendCategory::TravelCarRental,
            PurchaseType::CruiseLines => CardSpendCategory::TravelCruise,
            PurchaseType::Travel => CardSpendCategory::TravelGeneric,
            PurchaseType::CableSatelliteTvRadio => CardSpendCategory::MediaAndTelecom,
            PurchaseType::SoftwareSubscriptions => CardSpendCategory::Subscriptions,
            PurchaseType::ECommerce => CardSpendCategory::ECommerce,
            PurchaseType::Shipping => CardSpendCategory::Shipping,
            PurchaseType::Medical => CardSpendCategory::Medical,
            PurchaseType::Advertising => CardSpendCategory::Advertising,
            PurchaseType::MiscAndBusiness => CardSpendCategory::BusinessServices,
            PurchaseType::Ticketing => CardSpendCategory::Ticketing,
            PurchaseType::AutoWarranty => CardSpendCategory::AutoWarranty,
            PurchaseType::InsuranceUnderwritingAndPremiums,
            PurchaseType::InsuranceAndRealEstate => CardSpendCategory::Insurance,
            PurchaseType::RestaurantsAndFood => CardSpendCategory::Restaurants,
            PurchaseType::Tax => CardSpendCategory::Tax,
        };
    }

    /**
     * Reverse mapping for callers (e.g. the ConnexPay card requests) that need
     * to land on a concrete `PurchaseType`. Lossy only where the class
     * docblock says so.
     */
    public static function fromCategory(CardSpendCategory $category): PurchaseType
    {
        return match ($category) {
            CardSpendCategory::TravelAir => PurchaseType::Airline,
            CardSpendCategory::TravelLodging => PurchaseType::HotelAndResort,
            CardSpendCategory::TravelCarRental => PurchaseType::CarRental,
            CardSpendCategory::TravelCruise => PurchaseType::CruiseLines,
            // ConnexPay has no Rail-specific code — widen to generic Travel.
            CardSpendCategory::TravelRail,
            CardSpendCategory::TravelGeneric => PurchaseType::Travel,
            CardSpendCategory::MediaAndTelecom => PurchaseType::CableSatelliteTvRadio,
            CardSpendCategory::Subscriptions => PurchaseType::SoftwareSubscriptions,
            CardSpendCategory::ECommerce => PurchaseType::ECommerce,
            CardSpendCategory::Shipping => PurchaseType::Shipping,
            CardSpendCategory::Medical => PurchaseType::Medical,
            CardSpendCategory::Advertising => PurchaseType::Advertising,
            CardSpendCategory::Ticketing => PurchaseType::Ticketing,
            CardSpendCategory::AutoWarranty => PurchaseType::AutoWarranty,
            // ConnexPay has 31 (Underwriting) and 32 (RealEstate); we
            // collapsed them on our domain side and pick 31 as primary.
            CardSpendCategory::Insurance => PurchaseType::InsuranceUnderwritingAndPremiums,
            CardSpendCategory::Restaurants => PurchaseType::RestaurantsAndFood,
            CardSpendCategory::Tax => PurchaseType::Tax,
            // ConnexPay has no ServiceFee — 22 covers business services.
            CardSpendCategory::ServiceFee,
            CardSpendCategory::BusinessServices => PurchaseType::MiscAndBusiness,
        };
    }
}
