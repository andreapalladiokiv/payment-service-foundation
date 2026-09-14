<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\ValueObject;

use Techork\PaymentService\Common\ValueObject\CardBrand;

/**
 * Raw provider reason code to {@see ReasonCategory}, per network, table-driven.
 *
 * Per network because the same idea has a different code on each, and because the codes that
 * read the same do not always mean the same: a Visa `13.1`, a Mastercard `4853` and an Amex
 * `C08` are all "not received", and they are three different codes on three different
 * networks with three different evidence requirements behind them. That is why nothing here
 * maps a code *away* — {@see DisputeReason} keeps the brand and the raw code alongside the
 * category, and the requirements table (F6) and the hint logic key off the pair, not off this.
 *
 * ## The table is a seed, and it is deliberately conservative
 *
 * What is listed below is the set this service has seen in the plan's own examples and in
 * ordinary operational use. Each network's published code list is the authority for the rest,
 * and neither of the three is short: Visa's VCR runs to two dozen numbered codes, Amex's to
 * far more letters-and-digits. Codes this table does not know come back
 * {@see ReasonCategory::Uncategorised} rather than as a guess, and adding one is a line here
 * plus a test — a wrong category is worse than an absent one, because the absent one is
 * visibly absent while the wrong one travels into evidence requirements as though it were read
 * off the network's own list.
 *
 * `Uncategorised` is also not a failure. See its docblock for why an unknown code must not stop
 * a case with a deadline from being ingested.
 *
 * ## Which network owns a code is not answered here
 *
 * This table goes `(network, code) → category`. The inverse question — *which* network issues a
 * given code — is answered by
 * {@see \Techork\PaymentService\Common\ValueObject\ReasonCodeNetwork}, which holds these same three
 * code sets as membership lists.
 *
 * It lives in `Common` rather than here because a **provider adapter** has to ask it: Nuvei's
 * Chargeback DMN states a reason code and no card brand at all, so the brand has to be read off the
 * code at ingestion, and `src/Nuvei/` may not see this package. The same fact therefore stands in
 * two places, and the drift that invites is taken deliberately and bounded: this table is a
 * conservative seed of the codes whose *category* is known, that one is the namespace a code is
 * placed by, and the suite pins that this table never claims a code for a network that does not
 * issue it.
 *
 * ## Not an enum, on purpose
 *
 * The alternative design — one enum of every code across every network — is the one thing the
 * plan forbids outright, and the reason is this class's own shape: the codes are not
 * interchangeable, so an enum would be a list with three disjoint halves that every `match`
 * then has to split again. A per-brand lookup keeps each network's list readable next to the
 * others.
 */
final class ReasonCodeMapper
{
    /**
     * Keyed by {@see CardBrand} value, then by the provider's raw code exactly as it arrives —
     * case and punctuation preserved, because that is what the providers send (Visa and
     * Mastercard `13.1` / `4853`, Amex `C08`) and normalising it would mean inventing a
     * canonical form that none of them uses.
     *
     * The type is left to inference rather than annotated: the literal shape of the table is what
     * makes a typo in a code visible at review, and psalm infers every value as a `ReasonCategory`
     * anyway. An `array<string, array<string, ReasonCategory>>` annotation here is rejected as a
     * narrower type than the literal it declares (psalm's InvalidConstantAssignmentValue), which
     * would have to be answered with a suppression — a worse trade than reading the table.
     */
    private const array TABLE = [
        // Visa Claims Resolution. The 10.x family is fraud, 11.x authorisation, 12.x processing,
        // 13.x consumer disputes.
        CardBrand::Visa->value => [
            '10.1' => ReasonCategory::Fraud,
            '10.2' => ReasonCategory::Fraud,
            '10.3' => ReasonCategory::Fraud,
            '10.4' => ReasonCategory::Fraud,
            '10.5' => ReasonCategory::Fraud,
            '11.1' => ReasonCategory::Authorization,
            '11.2' => ReasonCategory::Authorization,
            '11.3' => ReasonCategory::Authorization,
            '12.1' => ReasonCategory::ProcessingError,
            '12.2' => ReasonCategory::ProcessingError,
            '12.3' => ReasonCategory::ProcessingError,
            '12.4' => ReasonCategory::ProcessingError,
            '12.5' => ReasonCategory::IncorrectAmount,
            '12.6' => ReasonCategory::Duplicate,
            '12.7' => ReasonCategory::ProcessingError,
            '13.1' => ReasonCategory::NotReceived,
            '13.2' => ReasonCategory::Cancelled,
            '13.3' => ReasonCategory::NotAsDescribed,
            '13.4' => ReasonCategory::NotAsDescribed,
            '13.5' => ReasonCategory::NotAsDescribed,
            '13.6' => ReasonCategory::CreditNotProcessed,
            '13.7' => ReasonCategory::Cancelled,
            '13.8' => ReasonCategory::CreditNotProcessed,
            '13.9' => ReasonCategory::NotReceived,
        ],

        // Mastercard, four-digit. The chargeback family this service sees is 48xx.
        CardBrand::Mastercard->value => [
            '4831' => ReasonCategory::IncorrectAmount,
            '4834' => ReasonCategory::Duplicate,
            '4837' => ReasonCategory::Fraud,
            '4840' => ReasonCategory::Fraud,
            '4841' => ReasonCategory::Cancelled,
            '4842' => ReasonCategory::ProcessingError,
            '4846' => ReasonCategory::ProcessingError,
            '4847' => ReasonCategory::ProcessingError,
            '4853' => ReasonCategory::NotReceived,
            '4854' => ReasonCategory::Cancelled,
            '4855' => ReasonCategory::NotAsDescribed,
            '4860' => ReasonCategory::CreditNotProcessed,
            '4863' => ReasonCategory::Fraud,
        ],

        // Amex, letter-prefixed: A is authorisation, C is the cardholder's own dispute, P is a
        // processing or presentment problem.
        CardBrand::Amex->value => [
            'A02' => ReasonCategory::Fraud,
            'A08' => ReasonCategory::Authorization,
            'C02' => ReasonCategory::CreditNotProcessed,
            'C05' => ReasonCategory::Cancelled,
            'C08' => ReasonCategory::NotReceived,
            'C14' => ReasonCategory::Duplicate,
            'C18' => ReasonCategory::Cancelled,
            'C28' => ReasonCategory::Cancelled,
            'C31' => ReasonCategory::NotAsDescribed,
            'C32' => ReasonCategory::NotAsDescribed,
            'C62' => ReasonCategory::Authorization,
            'P05' => ReasonCategory::IncorrectAmount,
            'P07' => ReasonCategory::ProcessingError,
            'P08' => ReasonCategory::ProcessingError,
            'P22' => ReasonCategory::ProcessingError,
            'P23' => ReasonCategory::ProcessingError,
        ],
    ];

    /**
     * Networks this table has no list for at all — Discover, JCB, UnionPay — answer
     * `Uncategorised` for every code rather than borrowing another network's numbers. Their
     * codes are not Visa's, and a shared numeric range reading the same on two networks is a
     * coincidence rather than a mapping.
     */
    public static function categorise(CardBrand $brand, string $rawCode): ReasonCategory
    {
        return self::TABLE[$brand->value][$rawCode] ?? ReasonCategory::Uncategorised;
    }

    public static function knows(CardBrand $brand, string $rawCode): bool
    {
        return isset(self::TABLE[$brand->value][$rawCode]);
    }
}
