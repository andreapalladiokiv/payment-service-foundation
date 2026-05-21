<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common;

/**
 * Type-stable placeholders returned to callers when a PII value has been
 * shredded.
 *
 * Each stub is chosen from a formally reserved range so that no real-world
 * input could ever collide with it:
 *
 *  - {@see EMAIL}          — `redacted@redacted.invalid`. RFC 2606 §2 reserves
 *                            the `.invalid` TLD as "always invalid"; ICANN
 *                            does not allow registration. Belt-and-suspenders:
 *                            FormRequest validation should also reject any
 *                            email under {@see RESERVED_EMAIL_DOMAINS}.
 *  - {@see PHONE}          — `+12025550100`. NANPA explicitly reserves the
 *                            range `(XXX) 555-0100` through `(XXX) 555-0199`
 *                            for fictional use (movies, drama, training
 *                            material). FormRequest validation should reject
 *                            any number matching {@see PHONE_FICTION_REGEX}.
 *  - {@see NAME}           — `[REDACTED]`. Square brackets fall outside the
 *                            standard name regex `[\p{L}\s\-']+`, so the
 *                            stub cannot be re-submitted as a real name.
 *  - {@see ADDRESS_LINE}   — `[REDACTED ADDRESS]`. Same reasoning + a
 *                            distinct sentinel for street/line fields.
 *  - {@see CITY}           — `[REDACTED]`. Same `[REDACTED]` token as
 *                            {@see NAME}; the surrounding column disambiguates
 *                            and avoids minting another sentinel.
 *  - {@see POSTAL_CODE}    — `[REDACTED]`. Postal codes vary too widely by
 *                            country to fabricate a safe-looking value
 *                            (numeric, alphanumeric, with/without spaces); a
 *                            literal sentinel signals "shredded" uniformly.
 *  - {@see COUNTRY}        — `ZZ`. ISO 3166-1 alpha-2 reserves the `AA`,
 *                            `QM–QZ`, `XA–XZ`, `ZZ` ranges for user
 *                            assignment; `ZZ` is CLDR's de-facto code for
 *                            "Unknown Region". Symfony Intl's
 *                            `Countries::exists()` rejects it, so the
 *                            {@see \Techork\PaymentService\Common\ValueObject\Country}
 *                            VO whitelists this single value as a sentinel
 *                            before falling through to the ICU check.
 *
 * The stubs are constants (not configurable) so that downstream consumers
 * can pattern-match against them without coordinating across services.
 */
interface ShreddingStubs
{
    public const string EMAIL = 'redacted@redacted.invalid';

    public const string PHONE = '+12025550100';

    public const string NAME = '[REDACTED]';

    public const string ADDRESS_LINE = '[REDACTED ADDRESS]';

    public const string CITY = '[REDACTED]';

    public const string POSTAL_CODE = '[REDACTED]';

    public const string COUNTRY = 'ZZ';

    /**
     * RFC 2606 + RFC 6761 reserved TLDs that must never appear in real user
     * input. Use to extend FormRequest email validation, e.g.
     * `'doesnt_end_with:'.implode(',', ShreddingStubs::reservedEmailDomains())`.
     *
     * @return list<string>
     */
    public const array RESERVED_EMAIL_DOMAINS = ['.invalid', '.test', '.example', '.localhost'];

    /**
     * Regex matching the entire NANP fictional range
     * `+<country><area>5550(1XX)`. Use as `not_regex` rule for phone fields.
     */
    public const string PHONE_FICTION_REGEX = '/^\+\d{1,3}\d{3}5550(1\d{2})$/';
}
