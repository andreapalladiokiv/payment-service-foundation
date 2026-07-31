<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\ValueObject;

/**
 * The industry where the virtual card will be utilized.
 *
 * ConnexPay-native codes, mirroring their published table
 * ({@see https://docs.connexpay.com/docs/purchase-types}). This is a spend
 * restriction, not a label: authorisations outside the declared industry are
 * declined, and ConnexPay's guidance is to pick the most restrictive code
 * that fits.
 */
enum PurchaseType: int
{
    case Airline = 1;
    case HotelAndResort = 2;
    case CarRental = 3;
    case CableSatelliteTvRadio = 4;
    case CruiseLines = 5;
    case Travel = 6;
    case ECommerce = 7;
    case Shipping = 8;
    case Medical = 11;
    case Advertising = 21;
    case MiscAndBusiness = 22;
    case Ticketing = 23;
    case AutoWarranty = 24;
    case InsuranceUnderwritingAndPremiums = 31;
    case InsuranceAndRealEstate = 32;
    case RestaurantsAndFood = 91;
    case Tax = 93;
    case SoftwareSubscriptions = 95;
}
