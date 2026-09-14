<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\ValueObject;

use DateTimeImmutable;
use Override;
use Techork\PaymentService\Domain\Dispute\Contract\DisputeAction;
use Techork\PaymentService\Domain\Dispute\Contract\DisputeActionVisitor;

/**
 * File the response to the case: the network is owed an argument, and this is the task that makes it.
 *
 * This is the only action that carries {@see EvidenceRequirements}, because it is the only one where
 * evidence is the point — the others end the case rather than answer it.
 *
 * ## The template belongs to the pair the *provider* states, and may still be null
 *
 * The requirements are looked up by the provider's own `(brand, raw reason code)` pair, read from the
 * case and passed to the rule that builds this action — never assembled from values a caller passed
 * in alongside, because a template built that way could answer a different code's question than the
 * one the case carries.
 *
 * Null here is a real state and not a gap: the table is deliberately incomplete, and a pair nobody
 * has written down yet leaves the case **still waiting on us, still carrying its deadline, and still
 * needing to be surfaced** — with a null template. It says "this pair is not mapped, or the provider
 * did not state it", never "this response needs nothing".
 *
 * ## The deadline is the provider's own date
 *
 * A plain `DateTimeImmutable`, like the aggregate's stored deadline and for the same reason: the
 * instant is the whole fact, and whether it is the provider's own date or one the application
 * derived is not carried on the value. It is non-nullable, and that is what makes the aggregate's
 * rule refuse an action it cannot build rather than describe a task with no window.
 */
final readonly class RespondDisputeAction implements DisputeAction
{
    public function __construct(
        public ?EvidenceRequirements $requirements,
        public DateTimeImmutable $respondBy,
    ) {}

    #[Override]
    public function accept(DisputeActionVisitor $visitor): mixed
    {
        return $visitor->visitRespond($this);
    }
}
