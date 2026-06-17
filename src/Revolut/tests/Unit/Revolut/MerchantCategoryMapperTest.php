<?php

declare(strict_types=1);

use Techork\PaymentService\Gateway\ValueObject\CardSpendCategory;
use Techork\PaymentService\Revolut\Concern\MerchantCategoryMapper;

it('maps travel verticals to the travel merchant control', function (CardSpendCategory $category) {
    expect(MerchantCategoryMapper::fromCategory($category))->toBe('travel');
})->with([
    CardSpendCategory::TravelAir,
    CardSpendCategory::TravelLodging,
    CardSpendCategory::TravelCruise,
    CardSpendCategory::TravelGeneric,
]);

it('maps ground transport verticals to the transport merchant control', function (CardSpendCategory $category) {
    expect(MerchantCategoryMapper::fromCategory($category))->toBe('transport');
})->with([
    CardSpendCategory::TravelRail,
    CardSpendCategory::TravelGround,
]);

it('maps restaurants and service fees to their Revolut buckets', function () {
    expect(MerchantCategoryMapper::fromCategory(CardSpendCategory::Restaurants))->toBe('restaurants')
        ->and(MerchantCategoryMapper::fromCategory(CardSpendCategory::ServiceFee))->toBe('services');
});

it('returns null for categories without a safe Revolut counterpart', function (CardSpendCategory $category) {
    expect(MerchantCategoryMapper::fromCategory($category))->toBeNull();
})->with([
    CardSpendCategory::Medical,
    CardSpendCategory::Insurance,
    CardSpendCategory::Tax,
    CardSpendCategory::Advertising,
    CardSpendCategory::Ticketing,
    CardSpendCategory::Subscriptions,
    CardSpendCategory::GeneralBusiness,
]);
