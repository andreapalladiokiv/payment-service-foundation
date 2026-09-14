<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\Port;

use Techork\PaymentService\Domain\Dispute\Port\Request\SubmitEvidenceRequest;

/**
 * Files the assembled evidence for a case, where the provider has an API that accepts it.
 *
 * A port of its own rather than a method on {@see DisputeActionsPort} or on
 * {@see AcceptDisputePort}, because no provider implements all three: **ConnexPay has no API
 * submission at all** — its CMS API is read-only and an operator files the response in the portal —
 * so an implementation of a single combined port would be forced to stub a submission it can never
 * honour, and a stub that throws is indistinguishable at the call site from a provider whose
 * submission failed. The split is the same one `PaymentIntent` makes between capture, cancel and
 * refund, and for the same reason.
 *
 * The evidence arrives as a whole {@see \Techork\PaymentService\Domain\Dispute\ValueObject\EvidencePackage}
 * — the items together with the brand and the raw reason code they answer — because a package
 * judged against another code's template can come back complete when it is not, so the pair travels
 * with the evidence rather than beside it.
 *
 * The outcome reports the state our side of the case reached. A call that fails outright throws;
 * a case the provider refuses to accept evidence for comes back as the provider left the case and
 * is the caller's to interpret from the outcome, not a silent success.
 */
interface SubmitEvidencePort
{
    public function submit(SubmitEvidenceRequest $request): SubmissionOutcome;
}
