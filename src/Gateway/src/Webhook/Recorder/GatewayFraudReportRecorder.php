<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Webhook\Recorder;

use DateTimeImmutable;
use Money\Money;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Records a cardholder's fraud report against a transaction — a TC40 / SAFE report.
 *
 * **This is not a dispute and must never reach the dispute aggregate.** A fraud report is the
 * issuer telling the network that a cardholder denied a charge; it charges nothing back, gets no
 * response window and has no evidence to file, so routing one into the case model would open a case
 * for something that is not one. It has its own interface, in the same directory, precisely so that
 * the two cannot be confused at a call site: {@see GatewayDisputeRecorder} takes disputes,
 * this takes reports, and neither type is expressible through the other.
 *
 * The reader is a rate numerator — Visa's VAMP — which counts reports per merchant and period
 * independently of what happened to any particular case; a report on a transaction that was never
 * disputed counts exactly as much as one that was.
 *
 * `$paymentIntentId` is our internal aggregate id (a uuid string), already resolved by the caller
 * through `TransactionIdResolver`, following {@see GatewayFeeRecorder}. `$amount` is the amount the
 * report is about and `$reportedAt` the moment the provider states it was reported — not the moment
 * we read it, because the numerator is per period and a backlog import would otherwise file a
 * month's reports into the wrong month.
 *
 * A report with no payment of ours behind it has no call here: the caller decides whether that is a
 * `Delay` (the reference may be written a moment later) or something to surface, as the ingestion
 * handlers already do for every other provider signal that names a payment.
 */
interface GatewayFraudReportRecorder
{
    public function onFraudReported(GatewayId $gatewayId, string $paymentIntentId, Money $amount, DateTimeImmutable $reportedAt): RecorderOutcome;
}
