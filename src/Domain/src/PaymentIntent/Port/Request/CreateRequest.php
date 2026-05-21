<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Port\Request;

use Money\Money;
use Techork\PaymentService\Common\Contract\ChallengeResult;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Domain\PaymentIntent\CaptureMethod;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;

final readonly class CreateRequest
{
    public function __construct(
        public PaymentIntentId $paymentIntentId,
        public Money $amount,
        public PaymentInstrument $instrument,
        public CaptureMethod $captureMethod,
        public BillingAddress $billingAddress,
        public ?ChallengeResult $challengeResult = null,
    ) {}
}
