<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject;

/**
 * Which card network issues a dispute reason code, read off the code itself.
 *
 * ## Why this is here and not with the rest of the dispute domain
 *
 * It is the one dispute fact a *provider adapter* needs and the domain cannot give it: Nuvei's
 * Chargeback DMN states `Chargeback.ChargebackReason` — `"10.4 - Other Fraud-Card Absent
 * Environment"` — and no card brand anywhere in the payload, and a case that cannot name its
 * network cannot be answered, because the evidence requirements are keyed on the
 * `(network, code)` pair. So the brand has to be established at ingestion, by the package that
 * reads the DMN, and `src/Nuvei/` may not see `Domain` (`tests/Arch/PackageHierarchyTest.php`).
 * `CardBrand` itself already lives here; the set of codes each network issues is the same kind of
 * fact about the same networks.
 *
 * ## The answer is read, never guessed
 *
 * Each network issues its reason codes in its own namespace and the three are structurally
 * disjoint — Visa's VCR is `NN.N`, Mastercard's is four digits, Amex's is a letter and two digits
 * — so a code that lands in exactly one list identifies that network outright. What is *not* done
 * here is inferring a network from the code's *shape*: a code this table does not hold is answered
 * null even where its shape looks like one of these namespaces, because a shape is a pattern we
 * noticed rather than a list a network publishes, and a case filed against the wrong network's
 * evidence requirements is worse than one that failed to file.
 *
 * A network with no list at all — Discover, JCB, UnionPay, and the thirteen other cases of
 * {@see CardBrand} — is therefore unreachable through this class by design. An adapter that knows
 * the brand states it and never calls this; this is the way in for the payloads that state only a
 * code.
 *
 * ## The lists are a seed, and refusal is the answer at their edge
 *
 * What is listed below is what this service has seen in the plan's own examples and in ordinary
 * operational use, not each network's complete published list — Visa's VCR runs to two dozen
 * numbered codes, Amex's to far more letters-and-digits. A real delivery can therefore carry a
 * code from a network we *do* model that this table has not caught up with, and the honest answer
 * there is null. The caller must then **refuse the delivery loudly** rather than record a case
 * with a borrowed network or an absent one: a failed webhook row is visible, recoverable by adding
 * a line here, and is not a case carrying another network's evidence requirements.
 */
final class ReasonCodeNetwork
{
    /**
     * Keyed by {@see CardBrand} value, then the network's codes exactly as they arrive — case and
     * punctuation preserved, because that is what the networks print and what a provider quotes
     * back (Visa `10.4`, Mastercard `4853`, Amex `C08`).
     *
     * A list rather than a lookup map, so that a code appearing under two networks stays visible
     * as a duplicated line instead of being silently resolved to whichever one came last:
     * {@see issuing()} refuses an ambiguous answer, and it can only do that if the duplication is
     * still there to see. `Domain\Dispute\ValueObject\ReasonCodeMapper` carries the categories for
     * these same codes and its suite pins that the two agree about which network owns which code.
     */
    private const array NAMESPACES = [
        // Visa Claims Resolution. The 10.x family is fraud, 11.x authorisation, 12.x processing,
        // 13.x consumer disputes.
        CardBrand::Visa->value => [
            '10.1', '10.2', '10.3', '10.4', '10.5',
            '11.1', '11.2', '11.3',
            '12.1', '12.2', '12.3', '12.4', '12.5', '12.6', '12.7',
            '13.1', '13.2', '13.3', '13.4', '13.5', '13.6', '13.7', '13.8', '13.9',
        ],

        // Mastercard, four-digit. The chargeback family this service sees is 48xx.
        CardBrand::Mastercard->value => [
            '4831', '4834', '4837', '4840', '4841', '4842', '4846', '4847',
            '4853', '4854', '4855', '4860', '4863',
        ],

        // Amex, letter-prefixed: A is authorisation, C is the cardholder's own dispute, P is a
        // processing or presentment problem.
        CardBrand::Amex->value => [
            'A02', 'A08',
            'C02', 'C05', 'C08', 'C14', 'C18', 'C28', 'C31', 'C32', 'C62',
            'P05', 'P07', 'P08', 'P22', 'P23',
        ],
    ];

    /**
     * The network that issues this code among the lists held above — or null when nothing settles
     * it: no list carries the code, or more than one does.
     *
     * Ambiguity is answered null rather than resolved. Today no code is in two lists, and the rule
     * exists so that a code pasted into a second one is a *detected* condition rather than a
     * silent pick — the alternative is a delivery filed against whichever network happens to be
     * listed first.
     */
    public static function issuing(string $rawCode): ?CardBrand
    {
        $found = null;

        foreach (self::NAMESPACES as $brand => $codes) {
            if (! in_array($rawCode, $codes, true)) {
                continue;
            }

            if ($found !== null) {
                return null;
            }

            $found = CardBrand::from($brand);
        }

        return $found;
    }
}
