<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Port\Request;

use Money\Money;
use Techork\PaymentService\Common\Contract\ChallengeResult;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Domain\PaymentIntent\CaptureMethod;
use Techork\PaymentService\Domain\PaymentIntent\PaymentInitiation;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;

/**
 * The payment an answered authentication belongs to, and the answer.
 *
 * Unlike {@see CreateRequest} the challenge result is required — a confirmation
 * without one is not a confirmation. The rest describes the payment in full
 * because an adapter that has yet to place it needs all of it, and one that
 * already has ignores what it does not need.
 */
final readonly class ConfirmChallengeRequest
{
    public function __construct(
        public PaymentIntentId $paymentIntentId,
        public ChallengeResult $challengeResult,
        public Money $amount,
        public PaymentInstrument $instrument,
        public CaptureMethod $captureMethod,
        public BillingAddress $billingAddress,
        public PaymentInitiation $initiation = PaymentInitiation::CardholderInitiated,
    ) {}
}
