<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Command;

use Money\Money;
use Techork\PaymentService\Common\Contract\ChallengeResult;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Domain\PaymentIntent\CaptureMethod;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;

interface CreatePaymentIntentCommand
{
    public function paymentIntentId(): PaymentIntentId;

    public function amount(): Money;

    public function instrument(): PaymentInstrument;

    public function captureMethod(): CaptureMethod;

    public function billingAddress(): BillingAddress;

    /** @return array<string, mixed> */
    public function metadata(): array;

    /**
     * Optional pre-authenticated challenge result from an external MPI
     * (e.g. 3dsintegrator). When present, the aggregate forwards it to
     * the gateway as evidence to claim the liability shift.
     */
    public function challengeResult(): ?ChallengeResult;
}
