<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Concern;

use LogicException;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;

/**
 * Holds what {@see \Techork\PaymentService\Gateway\GatewayFactory} configured a driver with, and
 * refuses to hand it out if nothing did.
 *
 * A driver built by hand and never configured used to produce a request with no decrypter, which
 * reached the provider as an empty card number. Failing at the point of the mistake beats failing
 * three layers away with the acquirer's wording.
 */
trait HoldsInfrastructure
{
    private ?GatewayInfrastructure $infrastructure = null;

    protected function infrastructure(): GatewayInfrastructure
    {
        return $this->infrastructure ?? throw new LogicException(
            static::class.' was not configured; build it through GatewayFactory.',
        );
    }

    /**
     * The three keys a request class still reads out of a parameter array, under the names it
     * reads them by.
     *
     * Transitional. Every request that has been given a typed constructor takes
     * {@see GatewayInfrastructure} itself and none of this; the method disappears with the last
     * one that has not.
     *
     * @return array<string, mixed>
     */
    protected function ambient(): array
    {
        $infrastructure = $this->infrastructure();

        return [
            'gateway' => $infrastructure->credential,
            'decrypter' => $infrastructure->decrypter,
            'referenceResolver' => $infrastructure->instruments,
        ];
    }
}
