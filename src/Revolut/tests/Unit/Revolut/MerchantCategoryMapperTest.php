<?php

declare(strict_types=1);

use Techork\PaymentService\Gateway\ValueObject\CardSpendCategory;
use Techork\PaymentService\Revolut\Concern\MerchantCategoryMapper;

/**
 * The closed `BusinessMerchantCategory` enum of the Revolut Business API.
 * Anything outside it rejects the whole create / update card request.
 */
const REVOLUT_MERCHANT_CATEGORIES = [
    'health', 'general', 'services', 'airlines', 'transport', 'accommodation',
    'utilities', 'shopping', 'financial', 'furniture', 'hardware', 'groceries',
    'fuel', 'entertainment', 'software', 'restaurants', 'advertising', 'cash',
    'education', 'government',
];

it('only ever emits values Revolut recognises', function (CardSpendCategory $category) {
    $mapped = MerchantCategoryMapper::fromCategory($category);

    expect(array_diff($mapped, REVOLUT_MERCHANT_CATEGORIES))->toBe([])
        ->and($mapped)->toBe(array_values(array_unique($mapped)));
})->with(CardSpendCategory::cases());

// ConnexPay restricts on every PurchaseType, so no CardSpendCategory may
// quietly produce an unrestricted Revolut card.
it('always emits a restriction', function (CardSpendCategory $category) {
    expect(MerchantCategoryMapper::fromCategory($category))->not->toBe([]);
})->with(CardSpendCategory::cases());

it('maps a category that fits one bucket to exactly that bucket', function (CardSpendCategory $category, string $expected) {
    expect(MerchantCategoryMapper::fromCategory($category))->toBe([$expected]);
})->with([
    [CardSpendCategory::TravelAir, 'airlines'],
    [CardSpendCategory::TravelLodging, 'accommodation'],
    [CardSpendCategory::TravelRail, 'transport'],
    [CardSpendCategory::TravelCarRental, 'transport'],
    [CardSpendCategory::Subscriptions, 'software'],
    [CardSpendCategory::ECommerce, 'shopping'],
    [CardSpendCategory::Restaurants, 'restaurants'],
    [CardSpendCategory::ServiceFee, 'services'],
    [CardSpendCategory::BusinessServices, 'services'],
    [CardSpendCategory::Medical, 'health'],
    [CardSpendCategory::Insurance, 'financial'],
    [CardSpendCategory::Tax, 'government'],
    [CardSpendCategory::Advertising, 'advertising'],
    [CardSpendCategory::Ticketing, 'entertainment'],
]);

it('spans several buckets when one domain category covers them all', function (CardSpendCategory $category, array $expected) {
    expect(MerchantCategoryMapper::fromCategory($category))->toBe($expected);
})->with([
    'a cruise fare is carriage and lodging' => [
        CardSpendCategory::TravelCruise, ['transport', 'accommodation'],
    ],
    'unqualified travel can land on any travel bucket' => [
        CardSpendCategory::TravelGeneric, ['airlines', 'accommodation', 'transport'],
    ],
    'pay-tv is telecom carriage and media' => [
        CardSpendCategory::MediaAndTelecom, ['utilities', 'entertainment'],
    ],
    'couriers surface as transport or business service' => [
        CardSpendCategory::Shipping, ['transport', 'services'],
    ],
    'warranty cover is an auto service and an insurance product' => [
        CardSpendCategory::AutoWarranty, ['services', 'financial'],
    ],
]);

it('restricts ServiceFee and BusinessServices alike, as ConnexPay 22 does', function () {
    expect(MerchantCategoryMapper::fromCategory(CardSpendCategory::BusinessServices))
        ->toBe(MerchantCategoryMapper::fromCategory(CardSpendCategory::ServiceFee));
});
