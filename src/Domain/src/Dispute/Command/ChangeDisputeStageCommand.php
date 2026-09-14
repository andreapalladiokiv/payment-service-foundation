<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\Command;

use Techork\PaymentService\Domain\Dispute\DisputeStage;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeId;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeSignal;

interface ChangeDisputeStageCommand
{
    public function disputeId(): DisputeId;

    public function stage(): DisputeStage;

    /**
     * Carries the provider's own cycle code alongside the stage, which is what makes a second
     * chargeback distinguishable from the first — see
     * {@see \Techork\PaymentService\Domain\Dispute\Event\DisputeStageChanged::$providerCode}, which
     * is where it lands and where a projection reads it from.
     */
    public function signal(): DisputeSignal;

    public function providerCode(): ?string;
}
