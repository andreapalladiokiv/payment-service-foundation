<?php

declare(strict_types=1);

namespace Techork\PaymentService\Revolut\Concern;

use Techork\PaymentService\Gateway\ValueObject\CardSpendCategory;

/**
 * Maps the platform-wide {@see CardSpendCategory} onto Revolut card
 * merchant-category controls (the `categories` allow-list on create /
 * update card).
 *
 * The accepted values are the closed `BusinessMerchantCategory` enum of the
 * Business API (see the `create-card` schema): health, general, services,
 * airlines, transport, accommodation, utilities, shopping, financial,
 * furniture, hardware, groceries, fuel, entertainment, software,
 * restaurants, advertising, cash, education, government. There is no
 * `travel` bucket — air, lodging and ground travel are separate values, and
 * a value Revolut does not recognise rejects the whole request.
 *
 * `categories` restricts the card the same way ConnexPay's `PurchaseType`
 * does — spend outside the listed buckets is declined. The only difference
 * is shape: ConnexPay picks one code, Revolut takes a list. So where a
 * single {@see CardSpendCategory} genuinely spans several Revolut buckets
 * (a cruise fare is carriage *and* lodging) all of them are emitted. That is
 * a translation of the same restriction, not a widening of it: the mapper
 * never adds a bucket the domain category does not cover, and every category
 * resolves to at least one bucket.
 *
 * Revolut's `general`, `furniture`, `hardware`, `groceries`, `fuel`,
 * `education` and `cash` buckets stay unreachable on purpose — no
 * {@see CardSpendCategory} covers them, and the domain does not invent cases
 * that another issuer could not honour.
 */
final class MerchantCategoryMapper
{
    public const string AIRLINES = 'airlines';

    public const string ACCOMMODATION = 'accommodation';

    public const string TRANSPORT = 'transport';

    public const string SERVICES = 'services';

    public const string RESTAURANTS = 'restaurants';

    public const string HEALTH = 'health';

    public const string FINANCIAL = 'financial';

    public const string GOVERNMENT = 'government';

    public const string ADVERTISING = 'advertising';

    public const string ENTERTAINMENT = 'entertainment';

    public const string UTILITIES = 'utilities';

    public const string SOFTWARE = 'software';

    public const string SHOPPING = 'shopping';

    /**
     * @return non-empty-list<string> the Revolut buckets to allow
     */
    public static function fromCategory(CardSpendCategory $category): array
    {
        return match ($category) {
            CardSpendCategory::TravelAir => [self::AIRLINES],
            CardSpendCategory::TravelLodging => [self::ACCOMMODATION],
            // Rail and car hire both sit in Revolut's single passenger
            // transport bucket.
            CardSpendCategory::TravelRail,
            CardSpendCategory::TravelCarRental => [self::TRANSPORT],
            // A cruise fare is carriage and lodging on one merchant; acquirers
            // classify cruise lines either way.
            CardSpendCategory::TravelCruise => [self::TRANSPORT, self::ACCOMMODATION],
            // Unqualified travel spend (agencies, consolidators, mixed
            // itineraries) can land on any of the three travel buckets.
            CardSpendCategory::TravelGeneric => [
                self::AIRLINES,
                self::ACCOMMODATION,
                self::TRANSPORT,
            ],
            // Pay-TV, satellite and radio: telecom carriage billed as a
            // utility, media sold as entertainment.
            CardSpendCategory::MediaAndTelecom => [self::UTILITIES, self::ENTERTAINMENT],
            CardSpendCategory::Subscriptions => [self::SOFTWARE],
            CardSpendCategory::ECommerce => [self::SHOPPING],
            // Revolut has no freight bucket; couriers surface either as
            // transport or as a business service depending on the acquirer.
            CardSpendCategory::Shipping => [self::TRANSPORT, self::SERVICES],
            CardSpendCategory::Medical => [self::HEALTH],
            CardSpendCategory::Insurance => [self::FINANCIAL],
            // Warranty cover is sold both as an automotive service and as an
            // insurance product.
            CardSpendCategory::AutoWarranty => [self::SERVICES, self::FINANCIAL],
            CardSpendCategory::Tax => [self::GOVERNMENT],
            CardSpendCategory::Advertising => [self::ADVERTISING],
            CardSpendCategory::Ticketing => [self::ENTERTAINMENT],
            CardSpendCategory::Restaurants => [self::RESTAURANTS],
            // Both land on ConnexPay 22, "Misc Advertising and Business
            // Services", so both restrict to business services here.
            CardSpendCategory::ServiceFee,
            CardSpendCategory::BusinessServices => [self::SERVICES],
        };
    }
}
