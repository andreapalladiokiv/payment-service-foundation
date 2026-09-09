<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\Contract;

use Techork\PaymentService\Common\ValueObject\AttachedPaymentMethod;
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
     * A stored instrument with nobody attached to it.
     *
     * Every payment operation declines this, and that is the contract rather than an oversight: a
     * stored card is charged to a person, the person is not derivable from the card, and the old
     * answer — read them off the address the payment method carried — is the behaviour the
     * customer split removes. {@see visitAttachedPaymentMethod()} is the payable one.
     *
     * The case stays on the visitor because vaulting, normalizing and card-summary extraction all
     * meet a bare payment method legitimately.
     *
     * @return T
     */
    public function visitPaymentMethod(PaymentMethod $paymentMethod): mixed;

    /**
     * A stored instrument and the customer it belongs to — what a payment operation takes.
     *
     * @return T
     */
    public function visitAttachedPaymentMethod(AttachedPaymentMethod $attached): mixed;

    /**
     * @return T
     */
    public function visitHostedPayment(HostedPayment $hosted): mixed;
}
