<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Role;

use Techork\PaymentService\Gateway\Command\CancelCommand;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Gateway\Exception\UnsupportedByGateway;

/**
 * Releasing a reservation that will not be taken.
 *
 * Named for what the caller wants rather than for the provider's word: this used to reach the
 * driver as `void()`, Omnipay's vocabulary, while every layer above called it a cancel.
 */
interface CancelsPayments
{
    /**
     * @throws UnsupportedByGateway
     */
    public function cancel(CancelCommand $command): GatewayResult;
}
