<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\Exception;

use DomainException;
use Techork\PaymentService\Domain\Dispute\DisputeStatus;

/**
 * A status change the table does not permit.
 *
 * {@see DisputeStatus::allowedTransitions()} is the authority and this exception is what "the
 * table throws" means in practice: the guard is the table itself rather than a second list of
 * permitted moves kept beside it, so a transition cannot become legal by being written into the
 * aggregate and not into the enum.
 *
 * A refusal here is a real defect somewhere upstream rather than a business outcome. Every
 * transition the system can produce has a route — a provider signal, our own submission, the
 * application's expiry command — so a move this refuses means an adapter has read a provider's
 * state wrongly, or an application has issued a command against a case it misread. Nothing is
 * recorded and the case is left exactly as it was, which is why the application is expected to
 * surface this rather than swallow it.
 *
 * Deliberately not thrown for a *repeat*: re-applying the status a case already holds is a
 * redelivery of a known fact and records nothing (see `DisputeAggregate::changeStatus()`). Only a
 * move to a genuinely different, unreachable state lands here.
 */
final class DisputeCannotChangeStatus extends DomainException
{
    public static function refused(DisputeStatus $from, DisputeStatus $to): self
    {
        $allowed = $from->allowedTransitions();
        $listed = $allowed === []
            ? 'no transitions out — it is terminal'
            : implode(', ', array_map(static fn (DisputeStatus $s): string => $s->value, $allowed));

        return new self(
            "A dispute in \"{$from->value}\" cannot move to \"{$to->value}\": $listed. The table "
            . 'in DisputeStatus::allowedTransitions() is the authority, and a move it does not list '
            . 'means a provider signal was read wrongly or a command was issued against a case the '
            . 'caller misread.',
        );
    }

    /**
     * `ACCEPTED` was asked for through the status command.
     *
     * It is a real transition — `NEEDS_RESPONSE` and `UNDER_REVIEW` both allow it — but it is not
     * a status the way the others are: it records *why* we stopped fighting, and only our own
     * deliberate concession may write it. Routing it through the generic status command would let
     * a provider signal name our own vocabulary back at us and read as though we had chosen to
     * concede.
     */
    public static function acceptanceGoesThroughAccept(DisputeStatus $from): self
    {
        return new self(
            "A dispute in \"{$from->value}\" was asked to move to \"accepted\" through the status "
            . 'command. ACCEPTED records our own deliberate concession rather than a provider\'s '
            . 'finding, and is written only by DisputeAggregate::accept().',
        );
    }
}
