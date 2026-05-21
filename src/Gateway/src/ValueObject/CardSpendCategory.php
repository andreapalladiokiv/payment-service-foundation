<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\ValueObject;

/**
 * Domain-level spend category for virtual card issuance.
 *
 * Designed as a balanced taxonomy for issuing gateways with different
 * native classifications:
 *
 *  - ConnexPay uses a wide MCC-style numeric enum (Airline=01,
 *    HotelAndResort=02, …). Most cases below map one-to-one to ConnexPay
 *    `PurchaseType` codes (see ConnexPay `PurchaseTypeMapper`).
 *  - Conferma uses a narrow 6-case enum (Generic, Air, Accommodation,
 *    Rail, Transport, ServiceFee). All `Travel*` cases map onto specific
 *    Conferma spend types; non-travel cases collapse to Generic. See
 *    Conferma `SpendTypeMapper`.
 *
 * Each gateway-package keeps its own mapper. This enum is the domain
 * source of truth — gateway-package implementations are private to
 * the package.
 */
enum CardSpendCategory: string
{
    // Travel verticals
    case TravelAir = 'travel_air';
    case TravelLodging = 'travel_lodging';
    case TravelRail = 'travel_rail';
    case TravelGround = 'travel_ground';
    case TravelCruise = 'travel_cruise';
    case TravelGeneric = 'travel_generic';

    // Other industries
    case Medical = 'medical';
    case Insurance = 'insurance';
    case Tax = 'tax';
    case Advertising = 'advertising';
    case Ticketing = 'ticketing';
    case Restaurants = 'restaurants';
    case Subscriptions = 'subscriptions';

    // Fees & catch-all
    case ServiceFee = 'service_fee';
    case GeneralBusiness = 'general_business';
}
