<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Contract;

use Techork\PaymentService\Gateway\ValueObject\GatewayId;

interface GatewayCredential
{
    public function getId(): GatewayId;

    public function getGatewayName(): string;

    /** @return array<string, string> */
    public function getCredentials(): array;
}
