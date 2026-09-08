<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Role;

use Techork\PaymentService\Gateway\Command\IssueCardCommand;
use Techork\PaymentService\Gateway\Command\TerminateCardCommand;
use Techork\PaymentService\Gateway\Command\UpdateCardCommand;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Gateway\Contract\VirtualCardResult;
use Techork\PaymentService\Gateway\Exception\UnsupportedByGateway;

/**
 * Issuing virtual cards: a card's whole life, as one role.
 *
 * Three methods together because they are one product rather than three capabilities. No provider
 * offers a subset — ConnexPay and Revolut do all three, Stripe, Nuvei and Paynet none — and no
 * caller issues a card without eventually adjusting or killing it.
 *
 * Separate from the acquiring roles for the same reason, read the other way: the two sets barely
 * overlap. Revolut acquires nothing and Stripe and Nuvei issue nothing, so a single composite
 * covering both would make four of five providers refuse most of what they declare.
 */
interface IssuesVirtualCards
{
    /**
     * @throws UnsupportedByGateway
     */
    public function issueVirtualCard(IssueCardCommand $command): VirtualCardResult;

    /**
     * @throws UnsupportedByGateway
     */
    public function updateVirtualCard(UpdateCardCommand $command): VirtualCardResult;

    /**
     * Terminating answers with a bare outcome, not a card: there is no card left to describe.
     *
     * @throws UnsupportedByGateway
     */
    public function terminateVirtualCard(TerminateCardCommand $command): GatewayResult;
}
