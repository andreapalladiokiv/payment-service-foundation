<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Port;

use Techork\PaymentService\Common\Contract\Challenge;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CreateRequest;

interface CreatePort
{
    /**
     * @return ?Challenge null when the gateway settled inline; non-null when
     *                    a step-up is required and confirmation is awaited.
     *
     * @throws GatewayDeclinedException
     */
    public function create(CreateRequest $request): ?Challenge;
}
