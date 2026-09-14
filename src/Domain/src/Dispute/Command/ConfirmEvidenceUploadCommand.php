<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\Command;

use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeId;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeSignal;

interface ConfirmEvidenceUploadCommand
{
    public function disputeId(): DisputeId;

    /**
     * Whether the provider has acknowledged.
     *
     * Carried as a value rather than expressed by calling or not calling, because "the provider
     * says it has not confirmed" is a signal with a key of its own and it must be absorbed rather
     * than leave the aggregate's trail of applied deliveries behind. ConnexPay's `HasResponse`
     * flips back to `false` on a later poll; Nuvei's callback never arrives. Neither is a
     * withdrawal, and neither may demote a submission (see
     * {@see \Techork\PaymentService\Domain\Dispute\SubmissionState::isTerminal()}).
     */
    public function confirmed(): bool;

    public function signal(): DisputeSignal;
}
