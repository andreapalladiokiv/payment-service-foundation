<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\Port\Request;

use InvalidArgumentException;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeId;

/**
 * Which case to ask about, whose name for it may or may not be one of ours.
 *
 * A case of ours is named by our `DisputeId`, and the adapter resolves the provider's reference
 * for it — Stripe's `dp_…`, Nuvei's id with its `/` and `+`, ConnexPay's `CaseNumber` — from
 * `gateway_references`, which is the only place the two names are held side by side. A case we
 * hold no aggregate for is named by the provider's reference and nothing else, because that is
 * literally all there is: no aggregate means no `DisputeId`, no row keyed by one, and a recorder
 * that has to ask about an unattributable ConnexPay case has nothing but the `CaseNumber` the
 * provider stated. The two shapes are the two constructors, so a caller cannot hand over a case
 * that is half-named, and an adapter that needs a reference reads a property that is never null
 * for the shape that carries one.
 *
 * ## Why our id rides along when the case is ours
 *
 * Part of the answer to "is this case waiting on us" is **our own state, which the provider's
 * payload cannot show**. A case we conceded reports as `lost` at the provider — its API knows only
 * that the merchant stopped fighting — and a case we let expire reports as nothing in particular;
 * both are non-empty answers at the provider and empty ones for us. An adapter that holds the
 * aggregate can read that, and the id is how it is handed one.
 *
 * ## A blank reference names nothing, and is refused
 *
 * `unattributable()` refuses one at construction, as every other blank value in this domain is
 * refused: a blank case name would address no case at all, and an adapter that read it would take
 * the provider's response for an unrelated — or for no — case and report it as this one's. It is
 * kept untrimmed once it is not blank, for the reason every provider value is: a reference is
 * compared against what the provider holds, not corrected on the way in.
 */
final readonly class AvailableActionsRequest
{
    private function __construct(private DisputeId|string $case) {}

    /** A case of ours: the adapter resolves the provider's reference from the reference table by this id. */
    public static function ofOurs(DisputeId $disputeId): self
    {
        return new self($disputeId);
    }

    /** A case we hold no aggregate for. Refuses a blank reference the way every other reference in this domain does. */
    public static function unattributable(string $providerReference): self
    {
        trim($providerReference) !== '' || throw new InvalidArgumentException(
            'An available-actions request was built for a case with a blank provider reference. A '
            . 'blank name addresses no case, so the provider\'s answer to it would be read as this '
            . 'case\'s answer — or as no answer — rather than as the mapping bug it is.',
        );

        return new self($providerReference);
    }

    public function disputeId(): ?DisputeId
    {
        return $this->case instanceof DisputeId ? $this->case : null;
    }

    /** Null when the case is ours — the adapter resolves it from the table in that case. */
    public function providerReference(): ?string
    {
        return is_string($this->case) ? $this->case : null;
    }
}
