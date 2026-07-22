<?php

declare(strict_types=1);

namespace Techork\PaymentService\Revolut\Concern;

/**
 * Normalises Revolut's card `expiry` ("MM/YYYY") to the digits-only `MMYYYY`
 * shape the platform's card layer parses (Carbon `mY`). Returns null when
 * there are no digits to parse.
 */
final class RevolutExpiry
{
    public static function normalize(?string $expiry): ?string
    {
        if ($expiry === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $expiry) ?? '';

        return $digits === '' ? null : $digits;
    }
}
