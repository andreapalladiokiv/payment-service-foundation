<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\ValueObject;

use InvalidArgumentException;
use RuntimeException;
use Techork\PaymentService\Common\ValueObject\CardBrand;

/**
 * What a network expects to see when a dispute on one reason code is answered.
 *
 * **Why the table is here and not in an adapter.** Both submission adapters need it —
 * Stripe's evidence fields and Nuvei's PDF package are two encodings of the same
 * question — and neither of them owns the answer. An adapter that carried its own copy
 * would state the requirements twice, and the two copies would drift apart without
 * anything failing loudly. This is the same reasoning that puts `CaseType` and
 * `ResolutionTo` in one place on the ConnexPay side.
 *
 * **Why the key is the pair, and why the code stays raw.** Visa `13.1`, Mastercard `4837`
 * and Amex `C08` are three different questions with three different answers, and a single
 * normalised reason enum collapses them — the evidence differences disappear along with
 * the distinction. The code is matched as the network writes it, untrimmed and
 * case-preserving, because a code we do not recognise is a gap to surface and not a
 * near-match to guess at; normalising the string is the first step back towards the enum
 * the whole design refuses. The brand is part of the key rather than decoration: Visa
 * `13.1` exists and Mastercard `13.1` does not, and a lookup that ignored the brand would
 * answer confidently about a code that brand never issues.
 *
 * **What is documented and what is not.** The plan's Provider Reference fixes endpoints,
 * status codes and `CaseType` mappings; it states no evidence requirement for any network.
 * Every code in the table is one the plan names — F1's `13.1` / `4853` / `C08` and F6's
 * `13.1` / `4837` / `10.4`. Every requirement set attached to them is this project's own
 * reading of what each code puts in issue, and is the part to re-check against the
 * networks' own documentation before the submission adapters ship. A code the plan never
 * mentions is deliberately absent: an invented entry would read as knowledge.
 *
 * **Failure is explicit, not silent.** {@see self::for()} refuses an unmapped pair,
 * because a caller that reaches it has already decided the template must exist — the
 * submission path, where answering with nothing would submit nothing. {@see self::tryFor()}
 * is the nullable form for every caller that must keep working around a gap: ingestion
 * sees reason codes from networks we have no table for, and a case must not be lost
 * because its requirements are not written down yet. That is the same distinction the
 * ingestion side draws everywhere else — surface the unmapped case, never default it.
 */
final readonly class EvidenceRequirements
{
    /**
     * The table. One entry per (brand, raw reason code) pair this project can answer for.
     *
     * The key is the brand's own value, a colon, then the code exactly as the network
     * writes it. The colon cannot make two pairs collide: everything before it is always
     * a brand value, so two keys can only be equal when both parts are.
     *
     * @var array<string, array{suppliedBySystem: list<EvidenceType>, requiredFromProject: list<EvidenceType>}>
     */
    private const array TABLE = [
        // Visa 13.1 — Merchandise/Services Not Received. The buyer says the goods never
        // arrived. The case is decided on whether they did, and secondarily on what we
        // told the buyer would happen if they cancelled — which is why the cancellation
        // trail belongs here and the accepted-terms record does not.
        'visa:13.1' => [
            'suppliedBySystem' => [
                EvidenceType::AvsCvvResult,
                EvidenceType::ThreeDsStatusAndLiabilityShift,
                EvidenceType::BuyerIpAddress,
                EvidenceType::AuthorizationTimestamp,
                EvidenceType::PaymentCardHistory,
                EvidenceType::StatementDescriptor,
            ],
            'requiredFromProject' => [
                EvidenceType::ProofOfDeliveryOrService,
                EvidenceType::CustomerCorrespondence,
                EvidenceType::CancellationPolicy,
                EvidenceType::CancellationConfirmation,
            ],
        ],

        // Visa 10.4 — Other Fraud, Card Present. The card was at the terminal and the
        // cardholder denies the charge. Nothing remote happened, so the buyer IP and the
        // 3DS outcome are not merely unavailable but meaningless — a template asking for
        // them would send a project looking for evidence that cannot exist. The signed
        // transaction receipt is proof the service was rendered, and it is the whole of
        // what the merchant side can add.
        'visa:10.4' => [
            'suppliedBySystem' => [
                EvidenceType::AvsCvvResult,
                EvidenceType::AuthorizationTimestamp,
                EvidenceType::PaymentCardHistory,
                EvidenceType::StatementDescriptor,
            ],
            'requiredFromProject' => [
                EvidenceType::ProofOfDeliveryOrService,
            ],
        ],

        // Mastercard 4837 — No Cardholder Authorization. The cardholder does not recognise
        // the charge. Unlike the "not received" codes this turns on whether the buyer was
        // ever at the checkout, so the accepted-terms record is evidence and the
        // cancellation trail is not: nothing was cancelled, the charge is denied outright.
        'mastercard:4837' => [
            'suppliedBySystem' => [
                EvidenceType::AvsCvvResult,
                EvidenceType::ThreeDsStatusAndLiabilityShift,
                EvidenceType::BuyerIpAddress,
                EvidenceType::AuthorizationTimestamp,
                EvidenceType::PaymentCardHistory,
                EvidenceType::StatementDescriptor,
            ],
            'requiredFromProject' => [
                EvidenceType::ProofOfDeliveryOrService,
                EvidenceType::CustomerCorrespondence,
                EvidenceType::TermsOfServiceAcceptance,
            ],
        ],

        // Mastercard 4853 — Cardholder Dispute. The sibling of Visa 13.1: goods or services
        // not received, or received as something other than described. Two networks, two
        // codes, one answer, and the table is keyed on the pair precisely so that this
        // entry can exist without Visa's being reachable from it.
        'mastercard:4853' => [
            'suppliedBySystem' => [
                EvidenceType::AvsCvvResult,
                EvidenceType::ThreeDsStatusAndLiabilityShift,
                EvidenceType::BuyerIpAddress,
                EvidenceType::AuthorizationTimestamp,
                EvidenceType::PaymentCardHistory,
                EvidenceType::StatementDescriptor,
            ],
            'requiredFromProject' => [
                EvidenceType::ProofOfDeliveryOrService,
                EvidenceType::CustomerCorrespondence,
                EvidenceType::CancellationPolicy,
                EvidenceType::CancellationConfirmation,
            ],
        ],

        // Amex C08 — Goods/Services Not Received or Partially Received. The same question
        // as the two above, asked by the third network the plan names.
        'amex:C08' => [
            'suppliedBySystem' => [
                EvidenceType::AvsCvvResult,
                EvidenceType::ThreeDsStatusAndLiabilityShift,
                EvidenceType::BuyerIpAddress,
                EvidenceType::AuthorizationTimestamp,
                EvidenceType::PaymentCardHistory,
                EvidenceType::StatementDescriptor,
            ],
            'requiredFromProject' => [
                EvidenceType::ProofOfDeliveryOrService,
                EvidenceType::CustomerCorrespondence,
                EvidenceType::CancellationPolicy,
                EvidenceType::CancellationConfirmation,
            ],
        ],
    ];

    /** @var list<EvidenceType> */
    private array $suppliedBySystem;

    /** @var list<EvidenceType> */
    private array $requiredFromProject;

    /**
     * Any array of types is accepted and normalised to a list, so that the accessors'
     * ordering contract holds regardless of how the caller assembled the template.
     *
     * @param array<array-key, EvidenceType> $suppliedBySystem
     * @param array<array-key, EvidenceType> $requiredFromProject
     */
    public function __construct(
        public CardBrand $cardBrand,
        public string $reasonCode,
        array $suppliedBySystem,
        array $requiredFromProject,
    ) {
        $reasonCode !== '' || throw new InvalidArgumentException('Evidence requirements must name the reason code they answer');

        $this->suppliedBySystem = self::assertGroup($suppliedBySystem, systemSupplied: true);
        $this->requiredFromProject = self::assertGroup($requiredFromProject, systemSupplied: false);
    }

    /**
     * The requirements for a pair, or a refusal when the table has none.
     *
     * @throws RuntimeException when the pair is not in the table — see the class docblock
     *                          for when to want that and when to want {@see self::tryFor()}.
     */
    public static function for(CardBrand $cardBrand, string $reasonCode): self
    {
        return self::tryFor($cardBrand, $reasonCode) ?? throw new RuntimeException(sprintf(
            'No evidence requirements are mapped for %s reason code "%s"',
            $cardBrand->value,
            $reasonCode,
        ));
    }

    /** The requirements for a pair, or null when the table has none. */
    public static function tryFor(CardBrand $cardBrand, string $reasonCode): ?self
    {
        $entry = self::TABLE[self::key($cardBrand, $reasonCode)] ?? null;

        return $entry === null ? null : new self(
            $cardBrand,
            $reasonCode,
            $entry['suppliedBySystem'],
            $entry['requiredFromProject'],
        );
    }

    /**
     * What the system attaches without being asked.
     *
     * @return list<EvidenceType>
     */
    public function suppliedBySystem(): array
    {
        return $this->suppliedBySystem;
    }

    /**
     * What the project has to produce.
     *
     * @return list<EvidenceType>
     */
    public function requiredFromProject(): array
    {
        return $this->requiredFromProject;
    }

    public function isRequiredFromProject(EvidenceType $type): bool
    {
        return in_array($type, $this->requiredFromProject, strict: true);
    }

    /**
     * What the project still owes.
     *
     * Only the project's side is checked. The system-supplied types describe what we
     * attach from our own records; whether we actually hold an AVS result or a buyer IP
     * for a given payment is a question about that payment, not a debt the project can be
     * chased for, and folding the two together would report a gap nobody can close.
     *
     * The package must answer the same pair — a package judged against another reason
     * code's template could come back complete when it is not, which is the single
     * mistake this table exists to prevent.
     *
     * @return list<EvidenceType>
     *
     * @throws InvalidArgumentException when the package answers a different pair
     */
    public function missingFrom(EvidencePackage $package): array
    {
        $package->cardBrand === $this->cardBrand && $package->reasonCode === $this->reasonCode
            || throw new InvalidArgumentException("Evidence for {$package->cardBrand->value} reason code \"$package->reasonCode\" cannot be judged against the requirements for {$this->cardBrand->value} \"$this->reasonCode\"");

        return array_values(array_filter(
            $this->requiredFromProject,
            static fn (EvidenceType $type): bool => ! $package->has($type),
        ));
    }

    /** Whether the package answers this template in full. */
    public function satisfiedBy(EvidencePackage $package): bool
    {
        return $this->missingFrom($package) === [];
    }

    private static function key(CardBrand $cardBrand, string $reasonCode): string
    {
        return "$cardBrand->value:$reasonCode";
    }

    /**
     * @param array<array-key, EvidenceType> $types
     *
     * @return list<EvidenceType>
     */
    private static function assertGroup(array $types, bool $systemSupplied): array
    {
        $seen = [];

        foreach ($types as $type) {
            $type->isSystemSupplied() === $systemSupplied
                || throw new InvalidArgumentException(sprintf(
                    'Evidence type "%s" is %s and cannot be listed as %s',
                    $type->value,
                    $type->isSystemSupplied() ? 'supplied by the system' : 'required from the project',
                    $systemSupplied ? 'supplied by the system' : 'required from the project',
                ));

            array_key_exists($type->value, $seen)
                && throw new InvalidArgumentException(sprintf(
                    'Evidence requirements list "%s" twice',
                    $type->value,
                ));

            $seen[$type->value] = true;
        }

        return array_values($types);
    }
}
