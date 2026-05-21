<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult;

/**
 * Maps ConnexPay response letter codes (Visa/Mastercard scheme standard)
 * into the normalized {@see CheckResult} enum.
 *
 * One AVS letter is decomposed into two normalized fields (street vs postal).
 * Lives inside the ConnexPay package — each gateway owns its own raw-format
 * translation. ConnexPay reports under top-level `addressVerificationCode`
 * and `cvvVerificationCode`, populated on both `/api/v1/verify` (tokenization)
 * and `/api/v1/authonlys` / `/api/v1/sales` transactions.
 */
final readonly class ConnexPaySchemeChecks
{
    /**
     * @return array{0: CheckResult, 1: CheckResult}
     */
    public static function avsToLineAndPostal(?string $letter): array
    {
        if ($letter === null || $letter === '') {
            return [CheckResult::Unchecked, CheckResult::Unchecked];
        }

        return match (strtoupper($letter)) {
            'Y', 'X', 'D', 'M', 'F' => [CheckResult::Pass, CheckResult::Pass],
            'A', 'B' => [CheckResult::Pass, CheckResult::Fail],
            'Z', 'P', 'W' => [CheckResult::Fail, CheckResult::Pass],
            'N', 'C' => [CheckResult::Fail, CheckResult::Fail],
            'U', 'G', 'I', 'R', 'S' => [CheckResult::Unavailable, CheckResult::Unavailable],
            'E', '0' => [CheckResult::Unchecked, CheckResult::Unchecked],
            default => [CheckResult::Unchecked, CheckResult::Unchecked],
        };
    }

    public static function cvvToCheckResult(?string $letter): CheckResult
    {
        if ($letter === null || $letter === '') {
            return CheckResult::Unchecked;
        }

        return match (strtoupper($letter)) {
            'M' => CheckResult::Pass,
            'N', 'S' => CheckResult::Fail,
            'P' => CheckResult::Unchecked,
            'U', 'X' => CheckResult::Unavailable,
            default => CheckResult::Unchecked,
        };
    }
}
