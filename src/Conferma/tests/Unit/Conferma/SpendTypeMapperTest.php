<?php

declare(strict_types=1);

use Techork\PaymentService\Conferma\Concern\SpendTypeMapper;
use Techork\PaymentService\Gateway\ValueObject\CardSpendCategory;
use Techork\PaymentService\Gateway\ValueObject\PurchaseType;

// ──────────────────────────────────────────────
//  CardSpendCategory → Conferma spendType
// ──────────────────────────────────────────────

it('maps TravelAir to Air', function () {
    expect(SpendTypeMapper::fromCategory(CardSpendCategory::TravelAir))->toBe('Air');
});

it('maps TravelLodging to Accommodation', function () {
    expect(SpendTypeMapper::fromCategory(CardSpendCategory::TravelLodging))->toBe('Accommodation');
});

it('maps TravelRail to Rail (Conferma has a dedicated rail bucket)', function () {
    expect(SpendTypeMapper::fromCategory(CardSpendCategory::TravelRail))->toBe('Rail');
});

it('maps TravelGround and TravelCruise to Transport', function () {
    expect(SpendTypeMapper::fromCategory(CardSpendCategory::TravelGround))->toBe('Transport')
        ->and(SpendTypeMapper::fromCategory(CardSpendCategory::TravelCruise))->toBe('Transport');
});

it('maps ServiceFee to Service Fee (with a space — legacy production form)', function () {
    expect(SpendTypeMapper::fromCategory(CardSpendCategory::ServiceFee))->toBe('Service Fee')
        ->and(SpendTypeMapper::SERVICE_FEE)->toBe('Service Fee');
});

it('falls back to Generic for non-travel categories', function () {
    foreach ([
        CardSpendCategory::TravelGeneric,
        CardSpendCategory::Medical,
        CardSpendCategory::Insurance,
        CardSpendCategory::Tax,
        CardSpendCategory::Advertising,
        CardSpendCategory::Ticketing,
        CardSpendCategory::Restaurants,
        CardSpendCategory::Subscriptions,
        CardSpendCategory::GeneralBusiness,
    ] as $category) {
        expect(SpendTypeMapper::fromCategory($category))->toBe('Generic');
    }
});

// ──────────────────────────────────────────────
//  Legacy PurchaseType bridge (kept for callers
//  still passing the wide enum)
// ──────────────────────────────────────────────

it('still maps legacy Airline to Air', function () {
    expect(SpendTypeMapper::fromPurchaseType(PurchaseType::Airline))->toBe('Air');
});

it('still maps legacy HotelAndResort to Accommodation', function () {
    expect(SpendTypeMapper::fromPurchaseType(PurchaseType::HotelAndResort))->toBe('Accommodation');
});

it('still maps legacy CarRental and CruiseLines to Transport', function () {
    expect(SpendTypeMapper::fromPurchaseType(PurchaseType::CarRental))->toBe('Transport')
        ->and(SpendTypeMapper::fromPurchaseType(PurchaseType::CruiseLines))->toBe('Transport');
});

it('still falls back to Generic for non-travel legacy verticals', function () {
    foreach ([
        PurchaseType::Travel,
        PurchaseType::Medical,
        PurchaseType::Advertising,
        PurchaseType::MiscAndBusiness,
        PurchaseType::Ticketing,
        PurchaseType::InsuranceUnderwritingAndPremiums,
        PurchaseType::InsuranceAndRealEstate,
        PurchaseType::RestaurantsAndFood,
        PurchaseType::Tax,
        PurchaseType::CableSatelliteTvRadio,
    ] as $purchase) {
        expect(SpendTypeMapper::fromPurchaseType($purchase))->toBe('Generic');
    }
});
