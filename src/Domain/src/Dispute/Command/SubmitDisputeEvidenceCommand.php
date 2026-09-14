<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\Command;

use DateTimeImmutable;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeId;
use Techork\PaymentService\Domain\Dispute\ValueObject\EvidenceType;

/**
 * The response went out.
 *
 * Ours rather than a delivery's — the submission is a call we made — so it carries a moment and
 * no signal. That is also why this is not the same event as the provider's acknowledgement of it:
 * see {@see ConfirmEvidenceUploadCommand} for the other half, and
 * {@see \Techork\PaymentService\Domain\Dispute\Event\EvidenceSubmitted} for why the two must not
 * be one thing.
 */
interface SubmitDisputeEvidenceCommand
{
    public function disputeId(): DisputeId;

    /** @return list<EvidenceType> */
    public function types(): array;

    public function at(): DateTimeImmutable;
}
