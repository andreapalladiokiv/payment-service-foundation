<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Webhook\Recorder;

use DateTimeImmutable;
use InvalidArgumentException;
use Money\Money;
use Techork\PaymentService\Common\ValueObject\CardBrand;

/**
 * A case as the provider currently states it, carried from an ingestion adapter to the recorder.
 *
 * ## The reference is required, and it is what ties the two recorder calls together
 *
 * {@see GatewayDisputeRecorder::onDisputeObserved()} is reached *through* a PaymentIntent — the
 * caller resolved one — so the PaymentIntent is its key and this reference rides inside the
 * snapshot. {@see GatewayDisputeRecorder::onDisputeResolved()} cannot be keyed that way: a
 * resolution can arrive for a case whose PaymentIntent never resolved, or after a backlog import,
 * so the provider's reference itself is the key there. **This field is the only thing that makes
 * the two calls describe one dispute**, so it is not optional and a blank one is refused — a blank
 * reference would be a constant, and a constant resolves every later delivery to the first case
 * that was ever stored.
 *
 * ## Stage and status are carried as the provider's own codes, not as domain values
 *
 * This package may not see `Domain` (see `tests/Arch/PackageHierarchyTest.php`), and duplicating
 * `DisputeStage` or `DisputeStatus` here would be a second spelling of a vocabulary the domain
 * already owns — one that would drift the first time a provider code was mapped in either copy.
 * So what travels is **the code the provider stated**: Stripe's `status`, Nuvei's
 * `DisputeUnifiedStatusCode`, ConnexPay's `CaseType`. Mapping it onto the domain's enums is done
 * once, outside this package, exactly as every other provider code in this tree is mapped.
 *
 * `$stageCode` is null where the provider states no stage of its own and its status is all there is
 * (Stripe's `warning_needs_response` implies an inquiry and says nothing else); `$statusCode` is
 * null where the provider states no status at all and its position has to be read from the fields
 * below (ConnexPay). Neither null means "unmapped": it means the provider did not say.
 *
 * ## …and therefore `$stage` and `$status` are the stage and the status, not the codes
 *
 * The pair above is the provider's word. It is **not** a stage, and no recorder can read one out of
 * it: the codes are three different vocabularies in one field (Stripe's `status`, Nuvei's
 * `DisputeUnifiedStatusCode`, ConnexPay's `CaseType`), and the table that turns each into a stage
 * lives in the package that owns the provider — which the recorder cannot see. Worse, for ConnexPay
 * the raw code is *not a stage at all*: `CaseType` 2 is a second chargeback in the same stage as
 * `CaseType` 1, so the code carries the **cycle position**, which the aggregate wants as the
 * provider's own code on its events (see the reference table's own note: "the cycle rides in the
 * event's `providerCode`, not in the stage").
 *
 * So the two facts travel separately. `$stageCode` / `$statusCode` are the provider's word, handed
 * to the aggregate untouched as the code that tells two visits to one stage apart; `$stage` /
 * `$status` are the domain's own spellings — `DisputeStage::Chargeback` is `'chargeback'` — filled
 * by the adapter that owns the provider's table, beside the code it read them from.
 *
 * Three states, and they are all different:
 *
 * - a spelling beside the code — the case is placed;
 * - **a raw code with no spelling** — the adapter read the payload and did not map it, which is a
 *   mapping gap in that adapter. A recorder must refuse this rather than fall back to the raw code:
 *   the code is not a stage, and reading `'1'` as one would file the case under whichever stage the
 *   reader guessed;
 * - **neither** — the provider said nothing about that axis, and a case that already exists is left
 *   as it is there.
 *
 * `$status` is the spelling `$statusCode` cannot always give. For ConnexPay there is no status code
 * at all (the CMS response has no such field), so its spelling is derived from `ResolutionTo` and
 * `WinLoss` and arrives here; for Stripe the two coincide for the four chargeback statuses and only
 * the spelling places `warning_*` in the inquiry phase.
 *
 * ## `$cardBrand` is not among them, and this is the reason
 *
 * Every other optional field here tolerates the provider staying silent. The brand does not, and
 * the difference is not a matter of taste: the evidence requirements are keyed on the
 * `(network, code)` pair, so a case with no network is not an incomplete case but an unanswerable
 * one. All three adapters therefore deliver one. ConnexPay states it as a numeric `CardBrand` and
 * refuses 5 (PayPal) by name rather than defaulting; Stripe states it as
 * `payment_method_details.card.brand` and refuses a delivery without one; and Nuvei, whose
 * Chargeback DMN states a reason code and no network anywhere, reads the network off the code's
 * namespace before building this value.
 *
 * That last one is why a provider adapter needs to know which network issues which code, and
 * therefore why that belongs in `Common` rather than in `Domain` — see
 * {@see \Techork\PaymentService\Common\ValueObject\ReasonCodeNetwork}. What must not happen is a
 * delivery arriving here with no brand at all: a recorder handed one would either invent a network
 * or file the case against another one's requirements. An adapter that cannot establish the brand
 * **refuses the delivery**, which is visible and recoverable, instead of reporting an absence this
 * DTO would have to accept.
 *
 * ## The fields a provider states in its own shape
 *
 * `$outcomeCode`, `$waitingOnCode` and `$hasResponse` are the three position signals ConnexPay
 * states separately from any status — `WinLoss`, `ResolutionTo` and `HasResponse` — and they are
 * nullable because every other provider leaves them null. They are carried rather than translated
 * because the translation is not this package's: `M` (merchant must respond) and `B` (bank must
 * respond) are what decide whether the case is waiting on us, and `WinLoss` is how a ConnexPay case
 * is ever `won` or `lost`. `$hasResponse` is a tri-state on purpose — null is "this provider states
 * nothing", which must not be read as a denial.
 *
 * `$familyRef` is the provider's own reference for the family a case belongs to, where its cases
 * re-reference themselves: ConnexPay's second chargeback carries a new `CaseNumber` inside the same
 * `FamilyId`, and this is what lets a recorder route it to the aggregate the first one opened. Null
 * for Stripe and Nuvei, whose cases never change identity.
 *
 * ## Fees, and why they are a shape rather than a value object
 *
 * A provider states fees as *codes*, and the domain's fee-type enum cannot be named here — a
 * Gateway-local copy of it would be the second vocabulary described above, and a `Domain\Dispute`
 * value object may not cross into this package at all.
 * So a fee is `code` (the provider's own, raw), `amount` and `chargedAt`, and the mapping onto the
 * domain's fee type happens on the other side of this boundary. A list rather than a map keyed by
 * code: the
 * same $20 charged on two different days is two fees, and a map would keep only the last.
 *
 * ## What the snapshot deliberately leaves out
 *
 * `NetPosition`, `HasImage`, `IsSurrendered`, `ImageFileUrl` and the raw cycle position of a
 * ConnexPay case are not here. The first four answer questions the aggregate does not ask (the fee
 * and settlement sides derive money from the settlement data, not from a case payload), and the
 * cycle position rides in `$stageCode` already. Anything a provider states that no field here can
 * hold belongs to the adapter's own case DTO, which is where F5's differ reads it.
 */
final readonly class DisputeSnapshot
{
    /**
     * @param string|null $stage the domain's own stage spelling (`'chargeback'`, `'inquiry'`), or
     *                           null when the provider stated nothing about the stage — see the
     *                           class docblock for why a raw `$stageCode` is not a substitute
     * @param string|null $status the domain's own status spelling (`'needs_response'`, `'won'`),
     *                            or null when the provider stated nothing
     * @param list<array{code: string, amount: Money, chargedAt: DateTimeImmutable}> $fees
     */
    public function __construct(
        public string $gatewayDisputeRef,
        public CardBrand $cardBrand,
        public string $reasonCode,
        public string $providerEventKey,
        public DateTimeImmutable $observedAt,
        public ?string $stageCode = null,
        public ?string $statusCode = null,
        public ?string $stage = null,
        public ?string $status = null,
        public ?string $outcomeCode = null,
        public ?string $waitingOnCode = null,
        public ?bool $hasResponse = null,
        public ?Money $disputedAmount = null,
        public ?DateTimeImmutable $responseDueAt = null,
        public ?string $familyRef = null,
        public array $fees = [],
    ) {
        trim($gatewayDisputeRef) !== '' || throw new InvalidArgumentException(
            'A dispute snapshot arrived with no provider reference. It is what ties the observed '
            . 'and resolved recorder calls to one case, and as a component of the lookup it would '
            . 'otherwise act as a wildcard matching whichever case was stored first.',
        );

        trim($providerEventKey) !== '' || throw new InvalidArgumentException(
            'A dispute snapshot arrived with no provider event key. It is the second half of the '
            . 'idempotency key the aggregate compares, and an empty one would make every later '
            . 'delivery of this case look identical to the first — every change would be dropped '
            . 'as a repeat.',
        );

        trim($reasonCode) !== '' || throw new InvalidArgumentException(
            'A dispute snapshot arrived with a blank reason code. It is half of the (brand, code) '
            . 'pair the evidence requirements are keyed on, and a blank one answers every '
            . 'unrecognised case with the same template. A provider statement that carries no '
            . 'reason code is a mapping bug in the adapter, not a case with no reason.',
        );
    }

    /**
     * Whether the provider stated a deadline of its own for this case.
     *
     * The date is nullable because a case can be raised before it has a window.
     */
    public function hasResponseDeadline(): bool
    {
        return $this->responseDueAt !== null;
    }
}
