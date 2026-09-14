<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\Command;

use DateTimeImmutable;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeId;
use Techork\PaymentService\Domain\Dispute\ValueObject\EvidenceType;

/**
 * The package left our hands for the provider's upload endpoint — `DRAFT` to `UPLOAD_PENDING`.
 *
 * The step that makes the waiting state exist at all, and it is deliberately not the same step as
 * filing the response. Nuvei takes one base64 PDF per request and acknowledges it on a callback
 * that has not arrived yet; while that is outstanding the file is at Nuvei and the response has
 * not been sent, which is exactly what `UPLOAD_PENDING` says and what neither `DRAFT` nor
 * `SUBMITTED` could.
 *
 * Ours, so a moment and no signal. ConnexPay never calls this: its portal upload has no API and
 * no acknowledgement, and its route runs `NONE` to `SUBMITTED` on an operator's word.
 */
interface UploadDisputeEvidenceCommand
{
    public function disputeId(): DisputeId;

    /** @return list<EvidenceType> */
    public function types(): array;

    public function at(): DateTimeImmutable;
}
