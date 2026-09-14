<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay\Dispute;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Exception\ParserException;
use Money\Exception\UnknownCurrencyException;
use Money\Parser\DecimalMoneyParser;

/**
 * Turns a provider's state snapshot into the `providerEventKey` the idempotency contract needs,
 * for the one provider that has no event id to offer.
 *
 * ## Why this lives in this package and not in `Domain`
 *
 * ConnexPay has no event id: its CMS API returns a case as it currently stands, and the only
 * thing that distinguishes one delivery from the next is the state itself. So its key is a hash
 * of the normalised snapshot, and the rules for computing it are part of the idempotency
 * contract. The plan fixes that contract in F1's section, in prose, and says who implements it:
 * "computed by the adapter in F5", "F5 implements it verbatim; do not improvise a second one".
 * This is that implementation, and it sits beside the field list it hashes rather than in the
 * domain — because it *has* to.
 *
 * A provider package may not name a `Domain\Dispute\*` type at all: `tests/Arch/PackageHierarchyTest.php`
 * admits `ConnexPay` to `['Common', 'Gateway']` and nothing else, so a hasher published in Domain
 * is unreachable from the three classes that need it — {@see CaseDiffer}, `CaseSnapshot` and
 * `DisputePoller` — and the contract would be stated exactly where its only caller is forbidden to
 * look. It is no better off in `Common` or `Gateway`: ConnexPay is the single provider with no
 * event id (Stripe uses the webhook `event.id`, Nuvei `DisputeEventId`), so either home would be a
 * cross-provider abstraction with one consumer.
 *
 * **What the aggregate is promised is nothing about the hash's internals.** It treats
 * `providerEventKey` as opaque — it compares it and never reads anything out of it — so this class
 * owes the aggregate no fixed field list. What it owes its own caller is that the same case hashes
 * the same on every poll and a changed case hashes differently, and that is what the tests pin.
 * The field list below is ConnexPay's own, assembled in {@see CaseSnapshot::hashFields()}.
 *
 * ## The contract `hashFields()` must meet
 *
 * Eleven fields, **in this order**, values already reduced to scalars:
 *
 * ```
 * FamilyId, CaseNumber, CaseType, ReasonCode, ResolutionTo, WinLoss,
 * HasResponse, HasImage, IsSurrendered, DueDate, NetPosition
 * ```
 *
 * In code:
 *
 * ```php
 * $canonicaliser->canonicalise([
 *     'FamilyId' => (string) $case->familyId,
 *     'CaseNumber' => (string) $case->caseNumber,
 *     'CaseType' => (string) $case->caseType,
 *     'ReasonCode' => $case->reasonCode,                       // raw, as stated
 *     'ResolutionTo' => $case->resolutionTo,
 *     'WinLoss' => $case->winLoss,
 *     'HasResponse' => $case->hasResponse,
 *     'HasImage' => $case->hasImage,
 *     'IsSurrendered' => $case->isSurrendered,
 *     'DueDate' => self::date($case->dueDate),
 *     'NetPosition' => self::amount($case->netPosition, 'USD'),
 * ]);
 * ```
 *
 * **The hash is of the case, not of the delivery.** `GetByUser` and `GetByResolvedDate` return
 * the same case in differently shaped payloads, sometimes rendering the same field as a number
 * on one and a string on the other, and they must produce the same hash for the same case.
 * That is what {@see self::date()}, {@see self::amount()} and {@see self::value()} are for:
 * every field goes through one of them rather than being concatenated as it arrived.
 *
 * ## The rules, and why each one is load-bearing
 *
 * - **Absent or `null` becomes the literal token `-`, and so does an empty string.** The CMS
 *   API returns both spellings for the same underlying "not set", so an absent field and an
 *   empty one must be indistinguishable — otherwise the same case hashes differently on two
 *   endpoints and looks like a new delivery the first time the payload's shape changes.
 * - **Dates are `Y-m-d` in UTC**, read as UTC when the payload names no zone. Not the process's
 *   timezone: a date-only value parsed against a machine's local time lands on the previous day
 *   west of Greenwich, so the same case would hash differently on two servers — and the key
 *   exists precisely so that it does not.
 * - **Amounts are integer minor units in the currency the payload states.** `-20.00` is
 *   `-2000`, and the currency is an argument because the value in the payload is a bare number
 *   with no currency in it. No float arithmetic: a cent lost to binary rounding is a key that
 *   stops matching.
 * - **Codes and enums are the raw provider value, case preserved and untrimmed.** `C08` is not
 *   `c08`, and trimming would change a key that has to match the same value on the next poll.
 * - **`field=value` pairs joined by `\n`, in the caller's order.** The order is part of the
 *   contract, so this preserves the array as given and never sorts — a sort would make two
 *   different field orders agree, which is the opposite of what a versioned key wants.
 * - **sha256, prefixed with the version.** `v1:` today. If the field list ever changes the
 *   version bumps to `v2:`, because a silent re-hash would make every open case look new and
 *   every one of them would be reported again. That is why the version is a constructor
 *   argument and not a string buried in the concatenation: `forVersion('v2')` is the whole
 *   upgrade path, and the prefix is applied outside the hash so a bump is visible in the key
 *   itself.
 *
 * ## What this class does not do
 *
 * It does not remember anything, compare anything, or decide whether a case changed. Comparing
 * the new key against the **most recent** one is the caller's ({@see CaseDiffer} stores it per
 * `CaseNumber`, and the aggregate compares the one it last applied) — and it must be the most
 * recent rather than a set of every key ever seen, because a case that returns to an earlier
 * combination of values legitimately returns to an earlier key. A set would suppress that
 * transition as a duplicate, and that transition is exactly what the `HasResponse` and `WinLoss`
 * mappings exist to report.
 *
 * A refusal here is not fatal to a poll: {@see DisputePoller} wraps the report of one case in a
 * blanket catch, so a snapshot this class will not hash becomes a `PollFailure` and the case is
 * offered again next cycle rather than the whole cycle dying with it.
 */
final readonly class ProviderSnapshotCanonicaliser
{
    /** The token an absent, null or empty field contributes. */
    public const string ABSENT = '-';

    private const string VERSION_PATTERN = '/^v[0-9]+$/';

    private function __construct(private string $version) {}

    /** The current contract: the eleven fields above, in the order above. */
    public static function v1(): self
    {
        return new self('v1');
    }

    /**
     * A later contract. The only reason to call this is a change to the field list, and the
     * resulting keys share no prefix with the previous version's — which is the point: an open
     * case must be re-reported once when the contract changes, and comparing a `v2` key against
     * a stored `v1` one would otherwise suppress it forever.
     */
    public static function forVersion(string $version): self
    {
        preg_match(self::VERSION_PATTERN, $version) === 1 || throw new InvalidArgumentException(
            "\"$version\" is not a valid snapshot contract version. Versions are `v` followed by "
            . 'digits, and the prefix is part of every key this class produces — a value that is '
            . 'not of that shape would make the version unreadable in a stored key and unmatchable '
            . 'against the next one.',
        );

        return new self($version);
    }

    public function version(): string
    {
        return $this->version;
    }

    /**
     * @param array<string, scalar|null> $fields ordered: the caller's order is part of the
     *                                          contract and is preserved exactly.
     */
    public function canonicalise(array $fields): string
    {
        // A hash of nothing is a constant, and a constant matches every later delivery as
        // readily as it matches the first — the same failure an empty key component would cause.
        // A caller that reaches here with no fields has a mapping bug, not a case with no state.
        $fields !== [] || throw new InvalidArgumentException(
            'A provider snapshot with no fields was canonicalised. The hash of nothing is a '
            . 'constant, and a constant matches every later delivery as readily as the first — the '
            . 'same failure an empty idempotency key component would cause. A caller that reached '
            . 'here with no fields has a mapping bug, not a case with no state.',
        );

        $pairs = [];

        foreach ($fields as $field => $value) {
            $pairs[] = $field . '=' . self::token($value);
        }

        return $this->version . ':' . hash('sha256', implode("\n", $pairs));
    }

    /**
     * The raw provider value for a code or an enum: case preserved, untrimmed, and `-` only
     * when there is nothing there at all. A whitespace-only value stays as it is — trimming
     * would be a correction, and a corrected key stops matching the provider's next statement
     * of the same value.
     *
     * Booleans are rendered `true` / `false` rather than PHP's `1` / `''`, because the two CMS
     * endpoints do not agree on how they spell one (`HasResponse` arrives as a JSON boolean on
     * one and as the string `"true"` on the other), and the contract says the same case must
     * hash the same on both.
     */
    public static function value(bool|int|float|string|null $value): string
    {
        return self::token($value);
    }

    /** Dates as `Y-m-d` UTC, or `-`. See the class docblock for why an unnamed zone means UTC. */
    public static function date(DateTimeInterface|string|null $value): string
    {
        if ($value === null || $value === '') {
            return self::ABSENT;
        }

        // The payload's own zone wins where it states one; where it does not, this default is
        // the answer, and it is deliberately not the machine's.
        $moment = $value instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($value)
            : new DateTimeImmutable($value, new DateTimeZone('UTC'));

        return $moment->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
    }

    /**
     * Amounts as integer minor units in the currency the payload states, or `-`.
     *
     * `$currency` is an argument and not part of the value because the CMS returns a bare
     * number: the currency is a configuration fact of the account being polled, and guessing it
     * from the API is not possible. Money's own decimal parser does the arithmetic, so no float
     * ever touches a cent.
     *
     * **`$amount` is the payload's own figure — a decimal amount, not a count of minor units.**
     * A JSON number and a decimal string both mean the same thing here: `-20.00` and `-20.0` and
     * `-20` are all twenty dollars, and an integer `2000` is two thousand dollars, not two
     * thousand cents. That is the only reading the CMS payloads support — they carry `NetPosition`
     * as a decimal figure — and it is worth stating because the `int` in the signature invites the
     * other one. A caller holding a minor-unit count has to render it in the currency's own scale
     * first; the currency's exponent is what decides the scale, which is exactly why the parser
     * needs the currency to do it correctly.
     *
     * An amount the parser cannot read is refused rather than reduced to `-`: see the catch below.
     */
    public static function amount(int|float|string|null $amount, string $currency): string
    {
        if ($amount === null || $amount === '') {
            return self::ABSENT;
        }

        // Minor units are meaningless without the currency, so an empty code is refused on the
        // same footing as an unreadable amount and with the same exception. Money refuses it too
        // (`Currency` will not take an empty code), and doing it here keeps the two routes to that
        // refusal identical from the outside.
        $currency !== '' || throw self::unhashableAmount((string) $amount, $currency);

        try {
            return (new DecimalMoneyParser(new ISOCurrencies()))
                ->parse((string) $amount, new Currency($currency))
                ->getAmount();
        } catch (ParserException|UnknownCurrencyException) {
            // Refused rather than reduced to `-`: an amount the parser cannot read is a mapping
            // bug or a payload this service does not understand, and hashing it as "absent"
            // would collide with a genuinely absent NetPosition — a different case's key.
            //
            // Caught by name rather than through Money's own `Exception`, and that is not a
            // preference: the interface does not extend `Throwable`, so a catch on it never
            // matches and psalm refuses it outright. These two are exactly what this call can
            // raise — `ParserException` for a value that is not a number,
            // `UnknownCurrencyException` for a code that is not ISO 4217 — and a third failure
            // added upstream would surface as itself rather than as a key that stopped matching.
            throw self::unhashableAmount((string) $amount, $currency);
        }
    }

    /**
     * The refusal an amount and a currency it cannot be read in share, so both routes to it say
     * the same thing from the outside.
     */
    private static function unhashableAmount(string $amount, string $currency): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            '"%s" is not an amount that can be read as %s. It is refused rather than reduced to '
            . 'the absent token, because the absent token is what a genuinely missing field '
            . 'contributes and the two would collide — giving one case the key of another.',
            $amount,
            $currency,
        ));
    }

    private static function token(bool|int|float|string|null $value): string
    {
        if ($value === null || $value === '') {
            return self::ABSENT;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
