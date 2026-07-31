<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\ValueObject;

/**
 * Domain-level spend category for virtual card issuance.
 *
 * This is a **restriction**, not a label: every issuing gateway we support
 * declines authorisations from merchants outside the chosen category, so the
 * category should be the narrowest one that fits the purchase.
 *
 * The taxonomy is deliberately no finer and no coarser than what issuers can
 * honour. ConnexPay is the binding constraint — it accepts exactly one code
 * from a closed list of 18 ({@see PurchaseType}) — so cases mostly stand
 * one-to-one with those codes. Two exceptions, both safe because the gateway
 * ends up *wider* than the domain rather than pointing elsewhere:
 * {@see self::TravelRail} and {@see self::ServiceFee} have no ConnexPay code
 * and fall back to `Travel` / `MiscAndBusiness` (see {@see PurchaseTypeBridge}).
 *
 * Gateways whose native controls are set-valued (Revolut takes a list of
 * merchant categories) may expand one case into several buckets. Each
 * gateway package keeps its own mapper; this enum is the domain source of
 * truth.
 *
 * Categories are not added speculatively. A case with no counterpart at an
 * issuer would force that issuer's mapper to pick a "closest fit" restriction
 * — which does not merely mislabel the card, it declines legitimate spend.
 */
enum CardSpendCategory: string
{
    // Travel verticals
    case TravelAir = 'travel_air';
    case TravelLodging = 'travel_lodging';
    case TravelRail = 'travel_rail';
    case TravelCarRental = 'travel_car_rental';
    case TravelCruise = 'travel_cruise';
    case TravelGeneric = 'travel_generic';

    // Other industries
    case MediaAndTelecom = 'media_and_telecom';
    case Subscriptions = 'subscriptions';
    case ECommerce = 'ecommerce';
    case Shipping = 'shipping';
    case Medical = 'medical';
    case Insurance = 'insurance';
    case AutoWarranty = 'auto_warranty';
    case Tax = 'tax';
    case Advertising = 'advertising';
    case Ticketing = 'ticketing';
    case Restaurants = 'restaurants';

    // Fees & business services
    case ServiceFee = 'service_fee';
    case BusinessServices = 'business_services';
}
