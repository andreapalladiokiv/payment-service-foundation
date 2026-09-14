<?php

declare(strict_types=1);

namespace Techork\PaymentService\Tests\Support;

use LogicException;
use Techork\PaymentService\Domain\Dispute\Port\DisputeActionsPort;
use Techork\PaymentService\Domain\Dispute\Port\Request\AvailableActionsRequest;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeActionSet;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeId;

/**
 * Answers a case with the action set it was set up with, and records what it was asked.
 *
 * One answer per provider reference, and no fallback: a reference the stub was not set up for
 * throws rather than answering "nothing is waiting on us". That is the state this port exists to
 * keep distinct, and a test that reached an unarmed reference has a case in mind that the stub
 * knows nothing about — silently returning the empty set would look like a passing assertion about
 * a case that is not waiting on us when it is really a fixture that was never written.
 *
 * It also resolves the way an adapter has to, because the two shapes of
 * {@see AvailableActionsRequest} are not interchangeable: a case we hold no aggregate for arrives
 * with the provider's reference and is answered under it, while a case of ours arrives as a
 * {@see DisputeId} and is only addressable at all through the row that turns it into a reference —
 * which `withReference()` is here to stand in for. A question about our own case with no such row
 * throws for the same reason an unarmed reference does: the adapter could not have asked it.
 *
 * The recorded request is the other half: what the port is handed is all an implementation has to
 * work from, so a test that cares whether the aggregate was named alongside the provider reference
 * reads it instead of trusting the caller.
 */
final class StubDisputeActionsPort implements DisputeActionsPort
{
    /** Every question this fake was asked, in order. */
    public array $requests = [];

    /**
     * @param array<string, DisputeActionSet> $answers keyed by the provider's reference
     * @param array<string, string> $references our `DisputeId` → the provider's reference for it
     */
    private function __construct(
        private readonly array $answers,
        private readonly array $references = [],
    ) {}

    public static function answering(string $disputeRef, DisputeActionSet $set): self
    {
        return new self([$disputeRef => $set]);
    }

    /** The row a real adapter reads: the provider's own name for a case we hold an aggregate for. */
    public function withReference(DisputeId $disputeId, string $providerReference): self
    {
        return new self($this->answers, [...$this->references, $disputeId->toString() => $providerReference]);
    }

    public function availableActions(AvailableActionsRequest $request): DisputeActionSet
    {
        $this->requests[] = $request;

        $reference = $this->referenceFor($request);

        return $this->answers[$reference]
            ?? throw new LogicException(
                "This stub was not set up for dispute {$reference}. "
                . 'Answering "nothing is waiting on us" here would report a case as closed that '
                . 'the test never described.',
            );
    }

    private function referenceFor(AvailableActionsRequest $request): string
    {
        if ($request->providerReference() !== null) {
            return $request->providerReference();
        }

        $disputeId = $request->disputeId()?->toString() ?? '';

        return $this->references[$disputeId]
            ?? throw new LogicException(
                "This stub holds no provider reference for dispute {$disputeId}. A case of ours is "
                . 'addressed at the provider through the reference table, so without that row the '
                . 'question could not be asked at all — arm the stub with withReference() first.',
            );
    }
}
