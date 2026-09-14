<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\Command;

use DateTimeImmutable;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeId;
use Techork\PaymentService\Domain\Dispute\ValueObject\EvidenceType;

/**
 * Evidence staged at the provider and not yet filed — Stripe's `submit: false`.
 *
 * Ours rather than a delivery's, so it carries a moment and no signal: the staging is a call we
 * made, and its response names the case rather than the event. Attaching again while the package
 * is still in `DRAFT` is a real change — the set of facts grew — and it is recorded; the state
 * simply does not move.
 */
interface AttachDisputeEvidenceCommand
{
    public function disputeId(): DisputeId;

    /**
     * The facts this package answers with — not their bytes. The aggregate records *what* was
     * staged; the content travels to the provider through a submission port, never through the
     * event stream, because a base64 PDF in an event payload is a blob in every snapshot for the
     * rest of the case's life and answers no question the aggregate asks.
     *
     * @return list<EvidenceType>
     */
    public function types(): array;

    public function at(): DateTimeImmutable;
}
