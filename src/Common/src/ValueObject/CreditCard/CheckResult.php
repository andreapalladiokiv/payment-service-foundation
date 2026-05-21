<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject\CreditCard;

/**
 * Normalized outcome of a single card-verification step (AVS street, AVS
 * postal, or CVC). The four states deliberately distinguish "we have a real
 * signal" from "we have no information":
 *
 *  - {@see Pass}        verification ran, value matched.
 *  - {@see Fail}        verification ran, value mismatched — actionable risk
 *                       signal (merchants may decline or step-up).
 *  - {@see Unavailable} verification was attempted but could not be performed
 *                       (issuer doesn't support it, scheme rejected, system
 *                       busy). No information either way.
 *  - {@see Unchecked}   verification was not requested. Either the merchant
 *                       didn't submit data to check, or the gateway operation
 *                       does not run that check at all.
 */
enum CheckResult: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case Unavailable = 'unavailable';
    case Unchecked = 'unchecked';
}
