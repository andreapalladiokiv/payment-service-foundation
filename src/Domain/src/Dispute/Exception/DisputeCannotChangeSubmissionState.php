<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\Exception;

use DomainException;
use Techork\PaymentService\Domain\Dispute\SubmissionState;

/**
 * A move on the submission axis that the table does not permit.
 *
 * The sibling of {@see DisputeCannotChangeStatus}, and separate from it on purpose: the two axes
 * are independent, and one exception covering both would let a caller catch a refusal without
 * knowing which axis refused it — which is exactly the confusion the split into two tables
 * exists to prevent.
 *
 * {@see SubmissionState::allowedTransitions()} is the authority. There is one refusal this raises
 * that is worth naming here, because it is the one the table is shaped around:
 * **nothing ever leaves `CONFIRMED`**, and nothing demotes out of `SUBMITTED` either. A provider
 * that stops confirming — `HasResponse: false` on a later poll — is reporting the absence of a
 * new fact, not a new one, and the aggregate absorbs that without recording anything rather than
 * coming through this guard. So a refusal naming `CONFIRMED` or `SUBMITTED` as the state it
 * would move *out of* means a caller has tried to express "the confirmation was withdrawn", which
 * is not a thing that can happen to a filing.
 */
final class DisputeCannotChangeSubmissionState extends DomainException
{
    public static function refused(SubmissionState $from, SubmissionState $to): self
    {
        $allowed = $from->allowedTransitions();
        $listed = $allowed === []
            ? 'no transitions out — it is terminal'
            : implode(', ', array_map(static fn (SubmissionState $s): string => $s->value, $allowed));

        return new self(
            "A dispute's submission is in \"{$from->value}\" and cannot move to \"{$to->value}\": "
            . "$listed. The table in SubmissionState::allowedTransitions() is the authority; a "
            . 'move it does not list means a submission adapter reported an outcome the flow cannot '
            . 'produce.',
        );
    }

    /**
     * The move is one the table lists, but not from *this* operation.
     *
     * Needed because two operations share a destination. `DRAFT` is reachable from
     * `UPLOAD_PENDING` — but only by the provider's answer, never by staging: while the file is
     * with the provider, its evidence is not ours to add to, and a case whose package grew while
     * an upload was outstanding would submit a set of facts the provider never saw. Quoting the
     * table at the caller here would be actively misleading, since the pair it tried *is* in the
     * table.
     *
     * @param list<SubmissionState> $reachableFrom
     */
    public static function notThisOperation(SubmissionState $from, string $operation, array $reachableFrom): self
    {
        $listed = implode(', ', array_map(static fn (SubmissionState $s): string => $s->value, $reachableFrom));

        return new self(
            "A dispute's submission is in \"{$from->value}\" and {$operation}() cannot record a "
            . "move from there: that operation is recorded from $listed only. The table in "
            . 'SubmissionState::allowedTransitions() lists where the state itself may go, but not '
            . 'every operation that reports arriving there is the same operation.',
        );
    }
}
