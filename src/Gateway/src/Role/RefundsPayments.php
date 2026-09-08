<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Role;

use Techork\PaymentService\Gateway\Command\RefundCommand;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Gateway\Exception\UnsupportedByGateway;

/**
 * Returning money, both ways it can be returned.
 *
 * Two methods, and not by taxonomy: `RefundAdapter` is one client and needs both, because
 * sending the money to another card is what it does when the original one refuses. That
 * sequencing used to live inside the router, which made it a rule of the gateway layer; it
 * belongs with the caller, who is also the one that supplied the alternative instrument.
 *
 * Whether a provider even has the second primitive varies — Nuvei pays out, ConnexPay returns to
 * a retry card, Stripe has neither — so `retryRefund` is the operation most likely to refuse, and
 * whether that refusal is marked decides whether the refund saga stops or records `RefundFailed`
 * and carries on. See {@see UnsupportedByGateway}.
 */
interface RefundsPayments
{
    /**
     * @throws UnsupportedByGateway
     */
    public function refund(RefundCommand $command): GatewayResult;

    /**
     * @throws UnsupportedByGateway
     */
    public function retryRefund(RefundCommand $command): GatewayResult;
}
