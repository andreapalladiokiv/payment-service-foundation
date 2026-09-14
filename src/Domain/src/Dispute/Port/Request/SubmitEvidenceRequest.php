<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\Port\Request;

use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeId;
use Techork\PaymentService\Domain\Dispute\ValueObject\EvidencePackage;

/**
 * The case to answer, the evidence to answer it with, and whether to send it.
 *
 * The case is named by **our own id**, and the adapter turns it into whatever its provider
 * addresses a case with: the mapping from a `DisputeId` to the provider's reference is a row in
 * `gateway_references`, and the reference table is the adapter's to read. Nothing here carries the
 * provider's own name for the case, which is what keeps a value the Domain has no other use for
 * out of the aggregate's vocabulary.
 *
 * ## `$stageOnly` is Stripe's dual control, and only Stripe has it
 *
 * Stripe updates evidence with `submit: false` — the evidence is staged on the dispute, visible in
 * the API and the dashboard, and invisible to the issuer — and a second call with `submit: true`
 * sends it. The flag says which of the two this call is, and with it the aggregate can sit in
 * {@see \Techork\PaymentService\Domain\Dispute\SubmissionState::Draft} while a human reviews what
 * is about to be filed.
 *
 * **An adapter whose provider has no staging step must refuse `true` rather than send.** Nuvei
 * uploads files into `UPLOAD_PENDING` and then files a response, ConnexPay has no submission at all;
 * for either of them "staged but not sent" is not expressible, and quietly doing the sending anyway
 * would put a file in front of an issuer that a reviewer had not yet approved — which is the one
 * thing the staging flow exists to prevent.
 *
 * ## Why the package is not optional
 *
 * A submission with no evidence is a concession wearing a response's clothes, and the provider's
 * answer to an empty evidence object is not a refusal. An empty package is expressible
 * ({@see EvidencePackage} with no items) and the caller that means it can say so; there is no way to
 * mean "submit, evidence to follow".
 */
final readonly class SubmitEvidenceRequest
{
    public function __construct(
        public DisputeId $disputeId,
        public EvidencePackage $evidence,
        public bool $stageOnly = false,
    ) {}
}
