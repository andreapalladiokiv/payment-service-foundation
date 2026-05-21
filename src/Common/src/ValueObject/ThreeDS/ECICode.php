<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject\ThreeDS;

enum ECICode: string
{
    /** Authentication failed (Mastercard) */
    case MastercardFailed = '00';

    /** Authentication Attempted (Mastercard) */
    case MastercardAttempted = '01';

    /** Authentication Successful (Mastercard) */
    case MastercardSuccessful = '02';

    /** Authentication Successful (Visa) */
    case VisaSuccessful = '05';

    /** Authentication Attempted (Visa) */
    case VisaAttempted = '06';

    /** Authentication Failed (Visa) */
    case VisaFailed = '07';
}
