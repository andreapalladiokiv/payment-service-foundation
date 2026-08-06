<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Port\Request;

use Money\Money;
use Techork\PaymentService\Common\Contract\Challenge;
use Techork\PaymentService\Common\Contract\ChallengeResult;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Domain\PaymentIntent\CaptureMethod;
use Techork\PaymentService\Common\ValueObject\PaymentInitiation;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;

/**
 * The payment an answered authentication belongs to, and the answer.
 *
 * Unlike {@see CreateRequest} the challenge result is required — a confirmation
 * without one is not a confirmation. The rest describes the payment in full
 * because an adapter that has yet to place it needs all of it, and one that
 * already has ignores what it does not need.
 *
 * `$challenge` is the one the payment is parked on, and it is here because the domain cannot
 * check that the result answers it. The two carry identifiers from different systems — a 3DS
 * server's transaction id on one side, the directory server's and the ACS's on the other — so
 * comparing them proves nothing, and the correlation is knowledge only the integration has. An
 * adapter that can establish it should, since without that step any result coherent enough to
 * pass inspection resolves any parked payment.
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
        public ?Challenge $challenge = null,
    ) {}
}
