<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\Contract;

use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\Token;

/**
 * @template T
 */
interface PaymentInstrumentVisitor
{
    /**
     * @return T
     */
    public function visitCreditCard(CreditCard $card): mixed;

    /**
     * @return T
     */
    public function visitCash(Cash $cash): mixed;

    /**
     * @return T
     */
    public function visitToken(Token $token): mixed;

    /**
     * @return T
     */
    public function visitPaymentMethod(PaymentMethod $paymentMethod): mixed;

    /**
     * @return T
     */
    public function visitHostedPayment(HostedPayment $hosted): mixed;
}
