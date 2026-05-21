<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject\ThreeDS;

/**
 * Terminal status of a completed 3DS authentication.
 *
 * The interim "Challenge Required" (C) state is represented separately by
 * {@see ThreeDSChallenge} — it is not a result, it is a pending interaction.
 */
enum ThreeDSStatus: string
{
    /** Authentication Successful */
    case Successful = 'Y';

    /** Authentication was not available, but functionality was available (liability shift) */
    case NotAvailable = 'A';

    /** Not Authenticated / Account Not Verified; Transaction denied */
    case NotAuthenticated = 'N';

    /** Authentication could not be performed; Technical or other problem */
    case NotPerformed = 'U';

    /** Authentication Rejected; Issuer is rejecting authentication */
    case Rejected = 'R';
}
