<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Refund\Port\Request;

use Money\Money;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Domain\PaymentIntent\Refund\ValueObject\RefundId;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;

final readonly class RefundRequest
{
    /**
     * @param  ?PaymentInstrument  $retryInstrument  When set, the refund must
     *  be credited to this alternative instrument instead of the original
     *  payment source. Gateway implementations decide whether that means a
     *  credit, a negative-amount purchase, or a refund variant that supports
     *  redirecting funds; the domain only requires the funds to leave the
     *  acquirer in the same currency and amount.
     */
    public function __construct(
        public PaymentIntentId $paymentIntentId,
        public RefundId $refundId,
        public Money $amount,
        public ?PaymentInstrument $retryInstrument = null,
    ) {}
}
