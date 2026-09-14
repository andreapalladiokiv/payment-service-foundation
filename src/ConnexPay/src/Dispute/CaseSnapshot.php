<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay\Dispute;

use DateTimeImmutable;
use DateTimeZone;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Throwable;

/**
 * One CMS chargeback case, as ConnexPay currently states it.
 *
 * ## It holds the provider's fields, and it does not decide anything
 *
 * Every field is the payload's own value: codes raw and untrimmed, money as the decimal figure
 * the payload carried, dates parsed but not normalised. Nothing here maps a `CaseType` to a stage
 * or a `ResolutionTo` to a waiter — that is {@see CaseMapping}, which the poller consults, so the
 * two readers of the same table cannot disagree. The one thing this class does own is
 * {@see self::hashFields()}, because the *field list* of the hash is ConnexPay's: the plan fixes
 * the contract and this package implements it.
 *
 * ## The field list is the idempotency contract
 *
 * {@see ProviderSnapshotCanonicaliser} fixes eleven fields in a fixed order, and this is where
 * they are assembled. The order is not alphabetical and not "whatever the payload had": it is the
 * contract, and the version prefix on the key is what makes a change to it survivable.
 *
 * `$familyId`, `$caseType`, `$reasonCode`, `$resolutionTo`, `$winLoss` and the two booleans are
 * the provider's own values; `$dueDate` and `$netPosition` go through the canonicaliser's date and
 * amount readers so that the same case hashes the same however either endpoint spelled it. That is
 * the point of the whole exercise: `GetByUser` and `GetByResolvedDate` return the same case in
 * differently shaped payloads, and a case that hashed differently on the second endpoint would
 * look like a new dispute every time the resolution cursor caught up with it.
 *
 * ## What is read, and where it comes from
 *
 * The field names below are the ones in ConnexPay's documented response — `FamilyId`,
 * `CaseNumber`, `CaseType`, `ReasonCode`, `CardBrand`, `ResolutionTo`, `Amount`, `DueDate`,
 * `OrderNumber`, `Arn`, `SaleGuid`, `NetPosition`, `WinLoss`, `HasResponse`, `HasImage`,
 * `IsSurrendered`. `ResolvedDate`, `AuthCode` and the rest of the 35 fields are not read: nothing
 * the snapshot contract can carry would hold them, and a field carried and never used is a field
 * that silently decays.
 *
 * `SaleGuid` is carried for the opposite reason, and it is not decoration: it is the provider's own
 * guid for the sale the case is against, and it is the **only key on the case that a stored
 * `gateway_references` row can be resolved by** once the sale is confirmed — the row holds this
 * guid, not the `OrderNumber` it held when the payment was placed. It is read here so that
 * {@see DisputePoller} can offer it to the resolver first; the order the three references are tried
 * in, and why, is documented there.
 *
 * The camelCase spelling of each is accepted as a fallback — ConnexPay's other payloads vary in
 * case and a `null` `OrderNumber` would route every case to the unmatched queue — but the
 * documented PascalCase is what the tables above name.
 *
 * ## Two refusals, both typed and neither a guess
 *
 * A case with no `CaseNumber`, no `ReasonCode`, an unreadable `DueDate`, or a `CardBrand` outside
 * 1-4 is refused by {@see UnrepresentableCase} rather than reported with a default. The poller
 * records the refusal and moves on; see that class for why each one is fatal to the case rather
 * than cosmetic.
 */
final readonly class CaseSnapshot
{
    public function __construct(
        /** ConnexPay's family: the first chargeback, its representments and its reversals share it. */
        public string $familyId,
        public string $caseNumber,
        /** The raw cycle code — `1` first chargeback, `2` second, `17` Amex retrieval, … */
        public string $caseType,
        public string $reasonCode,
        public CardBrand $cardBrand,
        public ?string $resolutionTo = null,
        public ?string $winLoss = null,
        public ?bool $hasResponse = null,
        public ?bool $hasImage = null,
        public ?bool $isSurrendered = null,
        /** The payload's own decimal figure, not a count of minor units — see the canonicaliser. */
        public int|float|string|null $netPosition = null,
        public int|float|string|null $amount = null,
        public ?DateTimeImmutable $dueDate = null,
        public ?string $orderNumber = null,
        public ?string $arn = null,
        /**
         * ConnexPay's guid for the sale this case is against, and the poller's primary matching key.
         *
         * Deliberately **not** one of {@see self::hashFields()}: the hash is a change detector over
         * the case's state, and this is stable identity — a guid that never changes cannot make two
         * visits to the same case look like two different disputes, and putting it in the field list
         * would only churn the contract's version for no answer.
         */
        public ?string $saleGuid = null,
    ) {}

    /**
     * @param array<string, mixed> $payload one case object from a CMS chargeback response
     *
     * @throws UnrepresentableCase when the case cannot be stated as a snapshot at all
     */
    public static function fromPayload(array $payload): self
    {
        $caseNumber = self::text($payload, 'CaseNumber');
        $caseNumber !== null && trim($caseNumber) !== '' || throw UnrepresentableCase::missingIdentity('CaseNumber');

        $reasonCode = self::text($payload, 'ReasonCode');
        $reasonCode !== null && trim($reasonCode) !== '' || throw UnrepresentableCase::missingIdentity('ReasonCode');

        return new self(
            familyId: self::text($payload, 'FamilyId') ?? '',
            caseNumber: $caseNumber,
            caseType: self::text($payload, 'CaseType') ?? '',
            reasonCode: $reasonCode,
            cardBrand: CaseMapping::cardBrand(self::scalar($payload, 'CardBrand')),
            resolutionTo: self::text($payload, 'ResolutionTo'),
            winLoss: self::text($payload, 'WinLoss'),
            hasResponse: self::flag($payload, 'HasResponse'),
            hasImage: self::flag($payload, 'HasImage'),
            isSurrendered: self::flag($payload, 'IsSurrendered'),
            netPosition: self::scalar($payload, 'NetPosition'),
            amount: self::scalar($payload, 'Amount'),
            dueDate: self::date($payload, 'DueDate'),
            orderNumber: self::text($payload, 'OrderNumber'),
            arn: self::text($payload, 'Arn'),
            saleGuid: self::text($payload, 'SaleGuid'),
        );
    }

    /**
     * The eleven fields the snapshot hash is taken over, in the contract's order.
     *
     * The currency is an argument because the CMS returns a bare number: `NetPosition` carries no
     * currency, and it is a fact of the account being polled. This method is the only place the
     * order exists, so a change to the field list is a change to one array and a version bump.
     *
     * @return array<string, bool|int|float|string|null>
     */
    public function hashFields(string $currency): array
    {
        return [
            'FamilyId' => $this->familyId === '' ? null : $this->familyId,
            'CaseNumber' => $this->caseNumber,
            'CaseType' => $this->caseType === '' ? null : $this->caseType,
            'ReasonCode' => $this->reasonCode,
            'ResolutionTo' => $this->resolutionTo,
            'WinLoss' => $this->winLoss,
            'HasResponse' => $this->hasResponse,
            'HasImage' => $this->hasImage,
            'IsSurrendered' => $this->isSurrendered,
            'DueDate' => ProviderSnapshotCanonicaliser::date($this->dueDate),
            'NetPosition' => ProviderSnapshotCanonicaliser::amount($this->netPosition, $currency),
        ];
    }

    /**
     * The family the caller routes a case by, or null when the provider stated none.
     *
     * Null rather than an empty string because the two mean different things to the recorder: an
     * empty reference would be looked up and would match nothing, while null says "this provider
     * did not state a family" — which is the ordinary answer for the two gateways that never
     * re-reference their cases.
     */
    public function familyRef(): ?string
    {
        return trim($this->familyId) === '' ? null : $this->familyId;
    }

    /**
     * A string field, in the documented spelling or the camelCase one.
     *
     * @param array<string, mixed> $payload
     */
    private static function text(array $payload, string $field): ?string
    {
        $value = $payload[$field] ?? $payload[lcfirst($field)] ?? null;

        if (is_array($value) || is_object($value) || is_bool($value)) {
            return null;
        }

        return $value === null ? null : (string) $value;
    }

    /**
     * A numeric field as the payload stated it, so the canonicaliser's own decimal reader — not a
     * cast here — decides what it means.
     *
     * @param array<string, mixed> $payload
     */
    private static function scalar(array $payload, string $field): int|float|string|null
    {
        $value = $payload[$field] ?? $payload[lcfirst($field)] ?? null;

        return is_int($value) || is_float($value) || is_string($value) ? $value : null;
    }

    /**
     * A boolean field.
     *
     * The two endpoints do not agree on how they spell one — `HasResponse` arrives as a JSON
     * boolean on one and as `"true"` on the other — and F1's contract needs them to hash the same.
     * So the spellings are normalised here, once, and both the hash and the snapshot read the same
     * value. Anything else, including an absent field, is null: "the provider stated nothing" is a
     * different answer from "the provider stated false", and the recorder's tri-state exists for
     * exactly that reason.
     *
     * @param array<string, mixed> $payload
     */
    private static function flag(array $payload, string $field): ?bool
    {
        $value = $payload[$field] ?? $payload[lcfirst($field)] ?? null;

        return match (true) {
            $value === null || $value === '' => null,
            is_bool($value) => $value,
            $value === 1 || $value === '1' || $value === 'true' || $value === 'TRUE' => true,
            $value === 0 || $value === '0' || $value === 'false' || $value === 'FALSE' => false,
            default => null,
        };
    }

    /**
     * A timestamp field.
     *
     * Read as UTC when the payload names no zone, which is what `DueDate` looks like in
     * ConnexPay's own sample (`2021-02-24T00:00:00`): a date-only value parsed against the
     * process's timezone lands on the previous day west of Greenwich, and the deadline that comes
     * out of it is the one escalation is built on.
     *
     * An empty field is absent, but an unreadable one is refused — see
     * {@see UnrepresentableCase::unreadableDate()}.
     *
     * @param array<string, mixed> $payload
     */
    private static function date(array $payload, string $field): ?DateTimeImmutable
    {
        $value = self::text($payload, $field);

        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            throw UnrepresentableCase::unreadableDate($field, $value);
        }
    }
}
