<?php

declare(strict_types=1);

use Techork\PaymentService\Gateway\ValueObject\CardSpendCategory;
use Techork\PaymentService\Gateway\ValueObject\PurchaseType;
use Techork\PaymentService\Gateway\ValueObject\PurchaseTypeBridge;

it('maps legacy PurchaseType to CardSpendCategory', function (PurchaseType $purchase, CardSpendCategory $expected) {
    expect(PurchaseTypeBridge::toCategory($purchase))->toBe($expected);
})->with([
    [PurchaseType::Airline, CardSpendCategory::TravelAir],
    [PurchaseType::HotelAndResort, CardSpendCategory::TravelLodging],
    [PurchaseType::CarRental, CardSpendCategory::TravelGround],
    [PurchaseType::CruiseLines, CardSpendCategory::TravelCruise],
    [PurchaseType::Travel, CardSpendCategory::TravelGeneric],
    [PurchaseType::CableSatelliteTvRadio, CardSpendCategory::Subscriptions],
    [PurchaseType::Medical, CardSpendCategory::Medical],
    [PurchaseType::Advertising, CardSpendCategory::Advertising],
    [PurchaseType::MiscAndBusiness, CardSpendCategory::GeneralBusiness],
    [PurchaseType::Ticketing, CardSpendCategory::Ticketing],
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
    [CardSpendCategory::TravelGround, PurchaseType::CarRental],
    [CardSpendCategory::TravelCruise, PurchaseType::CruiseLines],
    [CardSpendCategory::TravelRail, PurchaseType::Travel],
    [CardSpendCategory::TravelGeneric, PurchaseType::Travel],
    [CardSpendCategory::Subscriptions, PurchaseType::CableSatelliteTvRadio],
    [CardSpendCategory::Medical, PurchaseType::Medical],
    [CardSpendCategory::Advertising, PurchaseType::Advertising],
    [CardSpendCategory::Ticketing, PurchaseType::Ticketing],
    [CardSpendCategory::Insurance, PurchaseType::InsuranceUnderwritingAndPremiums],
    [CardSpendCategory::Restaurants, PurchaseType::RestaurantsAndFood],
    [CardSpendCategory::Tax, PurchaseType::Tax],
    [CardSpendCategory::ServiceFee, PurchaseType::MiscAndBusiness],
    [CardSpendCategory::GeneralBusiness, PurchaseType::MiscAndBusiness],
]);

it('round-trips most categories without loss', function (PurchaseType $original) {
    $roundtrip = PurchaseTypeBridge::fromCategory(PurchaseTypeBridge::toCategory($original));
    expect($roundtrip)->toBe($original);
})->with([
    [PurchaseType::Airline],
    [PurchaseType::HotelAndResort],
    [PurchaseType::CarRental],
    [PurchaseType::CruiseLines],
    [PurchaseType::Travel],
    [PurchaseType::CableSatelliteTvRadio],
    [PurchaseType::Medical],
    [PurchaseType::Advertising],
    [PurchaseType::Ticketing],
    [PurchaseType::InsuranceUnderwritingAndPremiums],
    [PurchaseType::RestaurantsAndFood],
    [PurchaseType::Tax],
]);

it('round-trip collapses InsuranceAndRealEstate into Underwriting form', function () {
    $original = PurchaseType::InsuranceAndRealEstate;
    $roundtrip = PurchaseTypeBridge::fromCategory(PurchaseTypeBridge::toCategory($original));
    expect($roundtrip)->toBe(PurchaseType::InsuranceUnderwritingAndPremiums);
});

it('round-trip collapses MiscAndBusiness for ServiceFee fallback', function () {
    expect(PurchaseTypeBridge::fromCategory(CardSpendCategory::ServiceFee))->toBe(PurchaseType::MiscAndBusiness)
        ->and(PurchaseTypeBridge::fromCategory(CardSpendCategory::GeneralBusiness))->toBe(PurchaseType::MiscAndBusiness);
});
