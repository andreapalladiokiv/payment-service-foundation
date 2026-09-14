<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay\Dispute;

use Techork\PaymentService\Common\ValueObject\CardBrand;

/**
 * Every ConnexPay CMS code this service translates, in one place — **including the one translation
 * that is still a reading rather than a fact**.
 *
 * ## Why the table lives here and not in the application
 *
 * Two tasks need it: F5 (this package's poller) and F9 (the operator handoff, which asks "is this
 * case waiting on us?" to decide whether an action is available). Two copies of the same provider
 * semantics inside one package drift apart the first time either is corrected, and nothing fails
 * loudly when they do — the dispute simply stops being actionable, or becomes actionable when it
 * is not. So the table is declared once, here, and F9 calls
 * {@see self::waitsOnMerchant()} instead of restating `M`.
 *
 * ## What travels on the wire, and what this class is for
 *
 * The codes travel **raw** on
 * {@see \Techork\PaymentService\Gateway\Webhook\Recorder\DisputeSnapshot} — F2's decision, and the
 * right one: `StageCode` is ConnexPay's own `CaseType` (which carries the *cycle position* — a
 * second chargeback is `CaseType` 2 inside the same family, and the case's stage history — a
 * projection of the event stream — can only tell the two visits apart by that number),
 * `WaitingOnCode` is the raw `ResolutionTo`, `OutcomeCode` is the raw
 * `WinLoss`. The application turns them into domain values, and what it turns them *with* is this
 * class: the mapping is here, the vocabulary is there.
 *
 * The one exception is the status, and it is not an exception by preference: the CMS API returns
 * **no status field at all** for the application to carry raw. See {@see self::status()}.
 *
 * ## `CaseType` → stage
 *
 * Verbatim from the plan's reference, which is verbatim from ConnexPay's own case-type table:
 *
 * | `CaseType` | Meaning                 | Stage             |
 * |---|---|---|
 * | 1          | First Chargeback        | `chargeback`      |
 * | 2          | Second Chargeback       | `chargeback`      |
 * | 3          | First Reversal          | `chargeback`      |
 * | 4          | Second Reversal         | `chargeback`      |
 * | 9          | Visa Pre-Arb            | `pre_arbitration` |
 * | 17         | Amex Retrieval          | `inquiry`         |
 * | 18         | Amex Chargeback         | `chargeback`      |
 * | 24         | Collaboration Pre-Arb   | `pre_arbitration` |
 *
 * **A `CaseType` outside that list has no stage, and none is invented.** ConnexPay's published
 * table is wider than the plan's — it also lists 0 (Retrieval), 11-16 (the Discover phases), 20,
 * 21, 25 and 28-31 — and its own API sample returns `CaseType` **25**, which the plan's table does
 * not contain. Mapping an unknown code to `chargeback` because chargebacks are the common case
 * would put a retrieval or an arbitration on the wrong branch of the aggregate and hand the
 * project a deadline and an evidence set belonging to another phase. {@see self::stage()} returns
 * null instead, and {@see DisputePoller} names the case on {@see PollResult::$unmappedCaseTypes}
 * so an operator sees it rather than a log line nobody reads.
 *
 * The stage spelling returned here is the domain's own enum value (`DisputeStage::Chargeback` is
 * `'chargeback'`), which is deliberate: the value has to be usable on the other side of the
 * boundary without a second translation table, and a second translation table is what moving this
 * mapping out of the package would create.
 *
 * ## `ResolutionTo` → who is waiting
 *
 * Quoting ConnexPay: it "indicates the party responsible for responding to a specific case".
 *
 * | `ResolutionTo` | Meaning                                          | Waiting on |
 * |---|---|---|
 * | `M`            | merchant must respond                            | us         |
 * | `B`            | bank must respond                                | the bank   |
 * | `S`            | resolved as a split (partial credit issued)      | nobody     |
 * | `G`            | moved to the general ledger, offset by a sibling | nobody     |
 *
 * Two predicates rather than a vocabulary: {@see self::waitsOnMerchant()} is the one F9 needs, and
 * {@see self::waitsOnBank()} exists so that "not waiting on us" is not read as "waiting on the
 * bank" — `S` and `G` are neither.
 *
 * ## `WinLoss` → the outcome, and the one reading in this file
 *
 * {@see self::status()} is **our reading, pending confirmation from ConnexPay**, and it is marked
 * as such on the method rather than presented as established. What the documentation does and does
 * not support is spelled out there.
 */
final class CaseMapping
{
    /**
     * @var array<int, string> `CaseType` => the domain's own stage spelling
     */
    private const array STAGE_BY_CASE_TYPE = [
        1 => 'chargeback',
        2 => 'chargeback',
        3 => 'chargeback',
        4 => 'chargeback',
        9 => 'pre_arbitration',
        17 => 'inquiry',
        18 => 'chargeback',
        24 => 'pre_arbitration',
    ];

    /**
     * The `WinLoss` values ConnexPay documents as decided, exactly as it spells them.
     *
     * `'Loss (Pending)'` is deliberately absent — see {@see self::status()}.
     */
    private const array LOST_WIN_LOSS = ['Loss'];

    private const array WON_WIN_LOSS = ['Win'];

    /**
     * The stage a `CaseType` belongs to, or null when the table does not cover it.
     *
     * Null is a statement about *this table*, not about the case: the provider did state a
     * `CaseType`, and the caller holding the raw value can still show it to an operator. Callers
     * must not treat null as `chargeback` — see the class docblock for what that would break.
     */
    public static function stage(string|int|null $caseType): ?string
    {
        $normalised = self::normalise($caseType);

        if ($normalised === null || ! ctype_digit($normalised)) {
            return null;
        }

        return self::STAGE_BY_CASE_TYPE[(int) $normalised] ?? null;
    }

    /**
     * Whether ConnexPay says the merchant — us — is the party responsible for responding.
     *
     * The only `ResolutionTo` that means the case is waiting on us. F9 reads this to decide
     * whether an action is available at all; nothing else in the cycle counts as ours.
     */
    public static function waitsOnMerchant(string|int|null $resolutionTo): bool
    {
        return self::normalise($resolutionTo) === 'M';
    }

    /** Whether ConnexPay has handed the case to the bank. Not the same as "not waiting on us". */
    public static function waitsOnBank(string|int|null $resolutionTo): bool
    {
        return self::normalise($resolutionTo) === 'B';
    }

    /**
     * The `DisputeStatus` this case stands in — **our reading, not yet confirmed by ConnexPay**.
     *
     * ## Why this cannot simply be carried raw
     *
     * Every other code on the snapshot is the provider's own word, carried untouched. There is no
     * such word for the status: the CMS response has **no status field**. Its 35 documented fields
     * include `ResolutionTo`, `HasResponse`, `IsSurrendered`, `WinLoss` and `ResolvedDate`, and
     * ConnexPay's own definitions of "Worked", "Not Worked", "Accepted" and "Expired" — the four
     * statuses its *portal* shows — describe conditions evaluated in the portal, not values
     * returned by the API. So a status has to be derived here or not exist, and F1's aggregate
     * cannot open a case without one.
     *
     * ## What the documentation supports, and what it does not
     *
     * Supported:
     *
     * - ConnexPay documents `WinLoss` as three values — `"Win"`, `"Loss"` and `"Loss (Pending)"` —
     *   and defines the first two as statements about the bank account ("if the Net Position is
     *   negative and the Status is Expired or Accepted" / "if the Net Position is $0.00 and the
     *   Chargeback was created over 5 days ago"). `"Win"` and `"Loss"` are therefore read as
     *   `won` and `lost`.
     * - ConnexPay documents `ResolutionTo = M` as "merchant is responsible for responding", which
     *   is what `needs_response` means; `B` hands the case to the bank, which is what
     *   `under_review` means.
     *
     * Not supported, and this is where our reading starts:
     *
     * - **`"Loss (Pending)"` is not read as a loss.** ConnexPay defines it as a negative net
     *   position whose *status is "Not Worked"*, or a debit that has not processed yet — that is a
     *   case still to be worked, and calling it `lost` would close a case that is still open for
     *   us to answer. It falls through to the `ResolutionTo` rule below, which is our reading of
     *   it rather than ConnexPay's statement.
     * - **`S` and `G` produce no status.** A split and a general-ledger move are resolutions with
     *   no counterpart in `DisputeStatus`, and neither is a win or a loss. They return null — the
     *   honest answer — rather than being squeezed into `under_review`, which would claim the
     *   network still has the case. *In practice a split or GL case carries a negative
     *   `NetPosition` and therefore a documented `WinLoss`, so it usually acquires a status
     *   through the rule above; the null path is for the case where it does not.*
     * - **`ACCEPTED` and `EXPIRED` are unreachable from this API.** Both exist in
     *   `DisputeStatus` and both are portal statuses at ConnexPay, but the response carries no
     *   field that states either, so no poll can ever report them. `ACCEPTED` is additionally
     *   reachable only by our own deliberate close (F7) at the gateways that support it, and
     *   ConnexPay has no submission API at all.
     *
     * ## The open item
     *
     * **This derivation must be confirmed against ConnexPay's own documentation before F7/F8/F9
     * rely on it**, exactly as the plan treats every other provider-code claim. The two rules above
     * are read off documented field definitions, but ConnexPay documents `WinLoss` as a *derived
     * display* — its field table leaves `WinLoss`'s own description empty — so reading it back as
     * an authoritative status is an inference about the field's role, not a quotation. The values
     * it is derived *from* are carried on the snapshot untouched (`outcomeCode`, `waitingOnCode`),
     * so correcting this method needs no change to the poller and no new column anywhere.
     *
     * An unrecognised or absent `WinLoss` never produces a status it cannot justify by falling
     * back to the raw code: it either matches one of the documented values or it does not.
     */
    public static function status(?string $resolutionTo, ?string $winLoss): ?string
    {
        $outcome = self::normalise($winLoss);

        if ($outcome !== null && in_array($outcome, self::WON_WIN_LOSS, true)) {
            return 'won';
        }

        if ($outcome !== null && in_array($outcome, self::LOST_WIN_LOSS, true)) {
            return 'lost';
        }

        // `Loss (Pending)` lands here on purpose, as does an absent or unrecognised WinLoss: the
        // case is not decided, so the question is who still has work to do, and only `ResolutionTo`
        // answers that.
        if (self::waitsOnMerchant($resolutionTo)) {
            return 'needs_response';
        }

        if (self::waitsOnBank($resolutionTo)) {
            return 'under_review';
        }

        return null;
    }

    /**
     * `CardBrand` (1-5) onto the network type the rest of the service speaks.
     *
     * 1 Visa, 2 Mastercard, 3 Discover, 4 Amex — and 5 is PayPal, refused by name rather than
     * guessed at. See {@see UnrepresentableCase::cardBrand()}.
     *
     * A `float` is in the signature because the payload's own scalar reader hands one over when
     * JSON renders the code as `1.0`; it compares as `'1'` and resolves like the integer it is.
     */
    public static function cardBrand(int|float|string|null $cardBrand): CardBrand
    {
        return match (self::normalise($cardBrand)) {
            '1' => CardBrand::Visa,
            '2' => CardBrand::Mastercard,
            '3' => CardBrand::Discover,
            '4' => CardBrand::Amex,
            default => throw UnrepresentableCase::cardBrand($cardBrand),
        };
    }

    /**
     * A provider scalar as a comparable string, or null when there is nothing there.
     *
     * The CMS renders the same field as a number on one endpoint and as a string on the other —
     * `CaseType` arrives as `"25"` in the documented sample and as `25` in a re-serialised
     * payload — so every code is compared as a string here. Nothing is trimmed or case-folded: a
     * value that does not match is a value we do not recognise, and correcting it would be a
     * mapping decision taken in a normaliser instead of in the table above.
     */
    private static function normalise(string|int|float|bool|null $value): ?string
    {
        if ($value === null || $value === '' || is_bool($value)) {
            return null;
        }

        return (string) $value;
    }
}
