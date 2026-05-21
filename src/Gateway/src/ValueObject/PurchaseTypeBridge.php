<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\ValueObject;

/**
 * Conversion bridge between the legacy {@see PurchaseType} (wide
 * ConnexPay-native enum) and the new domain {@see CardSpendCategory}.
 *
 * Used while existing call sites still build {@see PurchaseType} —
 * gateway-package mappers can normalize through this bridge to
 * {@see CardSpendCategory}.
 *
 * Mapping is one-to-one with one collapse: the legacy
 * `InsuranceUnderwritingAndPremiums` and `InsuranceAndRealEstate` both
 * fold into {@see CardSpendCategory::Insurance} — semantically they
 * were never distinguished on our domain side.
 */
final class PurchaseTypeBridge
{
    public static function toCategory(PurchaseType $purchaseType): CardSpendCategory
    {
        return match ($purchaseType) {
            PurchaseType::Airline => CardSpendCategory::TravelAir,
            PurchaseType::HotelAndResort => CardSpendCategory::TravelLodging,
            PurchaseType::CarRental => CardSpendCategory::TravelGround,
            PurchaseType::CruiseLines => CardSpendCategory::TravelCruise,
            PurchaseType::Travel => CardSpendCategory::TravelGeneric,
            PurchaseType::CableSatelliteTvRadio => CardSpendCategory::Subscriptions,
            PurchaseType::Medical => CardSpendCategory::Medical,
            PurchaseType::Advertising => CardSpendCategory::Advertising,
            PurchaseType::MiscAndBusiness => CardSpendCategory::GeneralBusiness,
            PurchaseType::Ticketing => CardSpendCategory::Ticketing,
            PurchaseType::InsuranceUnderwritingAndPremiums,
            PurchaseType::InsuranceAndRealEstate => CardSpendCategory::Insurance,
            PurchaseType::RestaurantsAndFood => CardSpendCategory::Restaurants,
            PurchaseType::Tax => CardSpendCategory::Tax,
        };
    }

    /**
     * Reverse mapping for callers (e.g. ConnexPay `PurchaseTypeMapper`)
     * that need to land on a concrete legacy `PurchaseType`. Lossy: the
     * `TravelRail` and `ServiceFee` cases pick a closest-fit code
     * because ConnexPay's wide enum has no dedicated Rail or ServiceFee
     * value.
     */
    public static function fromCategory(CardSpendCategory $category): PurchaseType
    {
        return match ($category) {
            CardSpendCategory::TravelAir => PurchaseType::Airline,
            CardSpendCategory::TravelLodging => PurchaseType::HotelAndResort,
            CardSpendCategory::TravelGround => PurchaseType::CarRental,
            CardSpendCategory::TravelCruise => PurchaseType::CruiseLines,
            // ConnexPay has no Rail-specific code — fall back to generic Travel.
            CardSpendCategory::TravelRail,
            CardSpendCategory::TravelGeneric => PurchaseType::Travel,
            CardSpendCategory::Subscriptions => PurchaseType::CableSatelliteTvRadio,
            CardSpendCategory::Medical => PurchaseType::Medical,
            CardSpendCategory::Advertising => PurchaseType::Advertising,
            CardSpendCategory::Ticketing => PurchaseType::Ticketing,
            // ConnexPay has 31 (Underwriting) and 32 (RealEstate); we
            // collapsed them on our domain side and pick 31 as primary.
            CardSpendCategory::Insurance => PurchaseType::InsuranceUnderwritingAndPremiums,
            CardSpendCategory::Restaurants => PurchaseType::RestaurantsAndFood,
            CardSpendCategory::Tax => PurchaseType::Tax,
            // ConnexPay has no ServiceFee — closest fit is MiscAndBusiness.
            CardSpendCategory::ServiceFee,
            CardSpendCategory::GeneralBusiness => PurchaseType::MiscAndBusiness,
        };
    }
}
