<?php

declare(strict_types=1);

use Techork\PaymentService\Gateway\ValueObject\CardSpendCategory;
use Techork\PaymentService\Gateway\ValueObject\PurchaseType;
use Techork\PaymentService\Gateway\ValueObject\PurchaseTypeBridge;

it('maps every ConnexPay PurchaseType to a CardSpendCategory', function (PurchaseType $purchase, CardSpendCategory $expected) {
    expect(PurchaseTypeBridge::toCategory($purchase))->toBe($expected);
})->with([
    [PurchaseType::Airline, CardSpendCategory::TravelAir],
    [PurchaseType::HotelAndResort, CardSpendCategory::TravelLodging],
    [PurchaseType::CarRental, CardSpendCategory::TravelCarRental],
    [PurchaseType::CruiseLines, CardSpendCategory::TravelCruise],
    [PurchaseType::Travel, CardSpendCategory::TravelGeneric],
    [PurchaseType::CableSatelliteTvRadio, CardSpendCategory::MediaAndTelecom],
    [PurchaseType::SoftwareSubscriptions, CardSpendCategory::Subscriptions],
    [PurchaseType::ECommerce, CardSpendCategory::ECommerce],
    [PurchaseType::Shipping, CardSpendCategory::Shipping],
    [PurchaseType::Medical, CardSpendCategory::Medical],
    [PurchaseType::Advertising, CardSpendCategory::Advertising],
    [PurchaseType::MiscAndBusiness, CardSpendCategory::BusinessServices],
    [PurchaseType::Ticketing, CardSpendCategory::Ticketing],
    [PurchaseType::AutoWarranty, CardSpendCategory::AutoWarranty],
    [PurchaseType::InsuranceUnderwritingAndPremiums, CardSpendCategory::Insurance],
    [PurchaseType::InsuranceAndRealEstate, CardSpendCategory::Insurance],
    [PurchaseType::RestaurantsAndFood, CardSpendCategory::Restaurants],
    [PurchaseType::Tax, CardSpendCategory::Tax],
]);

it('maps CardSpendCategory back to a closest-fit PurchaseType', function (CardSpendCategory $category, PurchaseType $expected) {
    expect(PurchaseTypeBridge::fromCategory($category))->toBe($expected);
})->with([
    [CardSpendCategory::TravelAir, PurchaseType::Airline],
    [CardSpendCategory::TravelLodging, PurchaseType::HotelAndResort],
    [CardSpendCategory::TravelCarRental, PurchaseType::CarRental],
    [CardSpendCategory::TravelCruise, PurchaseType::CruiseLines],
    [CardSpendCategory::TravelRail, PurchaseType::Travel],
    [CardSpendCategory::TravelGeneric, PurchaseType::Travel],
    [CardSpendCategory::MediaAndTelecom, PurchaseType::CableSatelliteTvRadio],
    [CardSpendCategory::Subscriptions, PurchaseType::SoftwareSubscriptions],
    [CardSpendCategory::ECommerce, PurchaseType::ECommerce],
    [CardSpendCategory::Shipping, PurchaseType::Shipping],
    [CardSpendCategory::Medical, PurchaseType::Medical],
    [CardSpendCategory::Advertising, PurchaseType::Advertising],
    [CardSpendCategory::Ticketing, PurchaseType::Ticketing],
    [CardSpendCategory::AutoWarranty, PurchaseType::AutoWarranty],
    [CardSpendCategory::Insurance, PurchaseType::InsuranceUnderwritingAndPremiums],
    [CardSpendCategory::Restaurants, PurchaseType::RestaurantsAndFood],
    [CardSpendCategory::Tax, PurchaseType::Tax],
    [CardSpendCategory::ServiceFee, PurchaseType::MiscAndBusiness],
    [CardSpendCategory::BusinessServices, PurchaseType::MiscAndBusiness],
]);

it('round-trips every PurchaseType except the two documented collapses', function (PurchaseType $original) {
    $roundtrip = PurchaseTypeBridge::fromCategory(PurchaseTypeBridge::toCategory($original));

    expect($roundtrip)->toBe($original);
})->with(array_filter(
    PurchaseType::cases(),
    static fn (PurchaseType $case): bool => $case !== PurchaseType::InsuranceAndRealEstate,
));

it('round-trip collapses InsuranceAndRealEstate into Underwriting form', function () {
    $original = PurchaseType::InsuranceAndRealEstate;
    $roundtrip = PurchaseTypeBridge::fromCategory(PurchaseTypeBridge::toCategory($original));
    expect($roundtrip)->toBe(PurchaseType::InsuranceUnderwritingAndPremiums);
});

it('widens the two categories ConnexPay has no code for', function () {
    expect(PurchaseTypeBridge::fromCategory(CardSpendCategory::TravelRail))->toBe(PurchaseType::Travel)
        ->and(PurchaseTypeBridge::fromCategory(CardSpendCategory::ServiceFee))->toBe(PurchaseType::MiscAndBusiness);
});

it('covers the whole published ConnexPay code table', function () {
    $codes = array_map(static fn (PurchaseType $case): int => $case->value, PurchaseType::cases());

    // https://docs.connexpay.com/docs/purchase-types
    expect($codes)->toBe([1, 2, 3, 4, 5, 6, 7, 8, 11, 21, 22, 23, 24, 31, 32, 91, 93, 95]);
});
