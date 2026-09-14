<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\Command;

use DateTimeImmutable;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeId;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeSignal;

interface ChangeDisputeDeadlineCommand
{
    public function disputeId(): DisputeId;

    /**
     * The date to store, as an instant. Nothing on the value says whether it is the provider's or one
     * we worked out — {@see self::signal()} is where that question is answered, and the event it
     * produces carries the answer as the presence or absence of a delivery key.
     */
    public function deadlineAt(): DateTimeImmutable;

    /**
     * The delivery that stated the date, or **null when we computed it**.
     *
     * The one command on this aggregate where a signal is optional, and the reason is that a
     * deadline has two sources rather than one. A provider states a date — `respond_by`, a DMN's
     * due date, ConnexPay's `DueDate` — and that arrives on a delivery; an application that
     * derives a date of its own, or revises one against a buffer, has nothing to point at. Null is
     * what it passes, and the recorded event carries no key because there is no delivery a repeat
     * of it could be recognised by.
     */
    public function signal(): ?DisputeSignal;

    /**
     * When we recorded the change.
     *
     * Used as the event's moment **only when {@see self::signal()} is null**: a provider's own
     * statement already carries when it was observed, and passing that moment twice would invite
     * the two to disagree.
     */
    public function recordedAt(): DateTimeImmutable;
}
