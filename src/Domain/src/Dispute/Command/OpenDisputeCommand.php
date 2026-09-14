<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\Command;

use DateTimeImmutable;
use Money\Money;
use Techork\PaymentService\Domain\Dispute\DisputeStage;
use Techork\PaymentService\Domain\Dispute\DisputeStatus;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeId;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeReason;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeSignal;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;

interface OpenDisputeCommand
{
    public function disputeId(): DisputeId;

    /**
     * The payment the case is about. A dispute has no meaning without one: the disputed amount is
     * money that moved on a payment we processed, and A4 books the loss against it.
     */
    public function paymentIntentId(): PaymentIntentId;

    public function stage(): DisputeStage;

    public function status(): DisputeStatus;

    public function reason(): DisputeReason;

    public function disputedAmount(): Money;

    /** Absent when the provider has not stated one yet — a case can be raised before it has a window. */
    public function deadlineAt(): ?DateTimeImmutable;

    /**
     * The provider's own cycle code for the stage the case is opening at, where the provider
     * states one.
     *
     * Carried here and not only on {@see ChangeDisputeStageCommand} because a case can be
     * *first observed* part-way through its cycle — a backlog import can open on ConnexPay's
     * `CaseType` 2 rather than 1 — and the opening entry of the stage history would then be
     * unable to say which cycle it was. It lands on
     * {@see \Techork\PaymentService\Domain\Dispute\Event\DisputeOpened::$providerCode}, which is
     * where a projection reads the opening cycle from.
     */
    public function providerCode(): ?string;

    /** The delivery that opened the case, which is also the first entry in its idempotency trail. */
    public function signal(): DisputeSignal;
}
