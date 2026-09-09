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
     * A stored instrument, attached to a customer or not.
     *
     * One case, because attached is a STATE of a payment method rather than a second type — see
     * {@see PaymentMethod::isAttached()}. There was a `visitAttachedPaymentMethod()` here for a
     * while and it split every implementation in two; what a payment mapper needs is not a
     * different branch but a refusal inside this one, because a stored card charged to nobody is
     * the defect and the customer is the only thing that decides it.
     *
     * @return T
     */
    public function visitPaymentMethod(PaymentMethod $paymentMethod): mixed;

    /**
     * @return T
     */
    public function visitHostedPayment(HostedPayment $hosted): mixed;
}
