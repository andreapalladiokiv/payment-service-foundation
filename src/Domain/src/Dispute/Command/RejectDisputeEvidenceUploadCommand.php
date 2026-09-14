<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\Command;

use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeId;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeSignal;

/**
 * A re-query shows the file was never accepted — `UPLOAD_PENDING` back to `DRAFT`.
 *
 * The one backwards move on either axis, and it exists because the alternative is a case waiting
 * forever on an upload the provider does not have. A poll that finds no file where we believed one
 * had been accepted is a provider delivery, so this carries a signal like any other.
 *
 * It puts the package back in our hands rather than raising an error: nothing was filed, the
 * response window is still running, and the honest state is "we hold the evidence again" — from
 * which `submitEvidence()` is reachable, where from `UPLOAD_PENDING` it would have been reachable
 * only by pretending the upload succeeded.
 */
interface RejectDisputeEvidenceUploadCommand
{
    public function disputeId(): DisputeId;

    public function signal(): DisputeSignal;
}
