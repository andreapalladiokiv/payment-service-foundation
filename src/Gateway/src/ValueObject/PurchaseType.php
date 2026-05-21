<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\ValueObject;

/**
 * The industry where the virtual card will be utilized.
 */
enum PurchaseType: int
{
    case Airline = 1;
    case HotelAndResort = 2;
    case CarRental = 3;
    case CableSatelliteTvRadio = 4;
    case CruiseLines = 5;
    case Travel = 6;
    case Medical = 11;
    case Advertising = 21;
    case MiscAndBusiness = 22;
    case Ticketing = 23;
    case InsuranceUnderwritingAndPremiums = 31;
    case InsuranceAndRealEstate = 32;
    case RestaurantsAndFood = 91;
    case Tax = 93;
}
