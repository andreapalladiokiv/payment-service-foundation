<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\Command;

use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeFee;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeId;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeSignal;

interface RecordDisputeFeeCommand
{
    public function disputeId(): DisputeId;

    /**
     * The whole fee, because its identity is all three of its parts: the same $20 charged twice
     * on two different days is two fees, and the aggregate needs the date to tell them apart.
     */
    public function fee(): DisputeFee;

    public function signal(): DisputeSignal;
}
