<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Contract;

use Techork\PaymentService\Gateway\ValueObject\GatewayId;

interface GatewayCredentialRepository
{
    public function findOrFail(GatewayId $gatewayId): GatewayCredential;

    /**
     * Returns every configured gateway credential. Used by webhook routing to
     * iterate candidates across tenants and gateway kinds.
     *
     * @return iterable<GatewayCredential>
     */
    public function all(): iterable;
}
