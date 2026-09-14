<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\Command;

use Techork\PaymentService\Domain\Dispute\DisputeStatus;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeId;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeSignal;

interface ChangeDisputeStatusCommand
{
    public function disputeId(): DisputeId;

    public function status(): DisputeStatus;

    public function signal(): DisputeSignal;
}
