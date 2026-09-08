<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Command;

use Money\Money;
use Techork\PaymentService\Common\Contract\CustomerIdentifier;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Take money that was already reserved.
 *
 * One typed object where the gateway roles
 * had six positional arguments, four of them optional. That signature had to be restated at
 * every layer it crossed — the interface, the router, the router's log array, the parameter
 * array handed to the provider — and a value dropped at any one of them was invisible: commit
 * `ba5d78c` fixed exactly that, two calls that stopped passing their parameters and reported
 * the resulting "The money parameter is required" to merchants as a gateway refusal.
 *
 * Adding a field is now one edit here. The log context and the provider parameters are both
 * derived from this object, so neither can fall out of step with it.
 */
final readonly class CaptureCommand
{
    /**
     * @param  ?Money  $authorizedAmount  The amount originally reserved. Only providers without
     *   a native partial capture need it — ConnexPay compares the two to decide between a plain
     *   capture and voiding the authorization to run a fresh sale — and that fallback is also
     *   why it may need `$instrument`. Providers with native partial capture ignore both.
     * @param  ?CustomerIdentifier  $customerId  Whose payment this is, for the acquirers that record it on a
     *   capture as well as on the authorization — ConnexPay accepts `CustomerID` on Capture and
     *   not on Void or Return. A caller that named a customer on the authorization sends the same
     *   value here, so an overwrite writes what was already there; one that named none never had
     *   a value to lose.
     */
    public function __construct(
        public GatewayId $gatewayId,
        public string $transactionReference,
        public Money $amount,
        public ?string $clientUniqueId = null,
        public ?Money $authorizedAmount = null,
        public ?PaymentInstrument $instrument = null,
        public ?CustomerIdentifier $customerId = null,
    ) {}


    /**
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'gatewayId' => $this->gatewayId->toString(),
            'transactionReference' => $this->transactionReference,
            'amount' => $this->amount,
            'clientUniqueId' => $this->clientUniqueId,
            'authorizedAmount' => $this->authorizedAmount,
            'instrument' => $this->instrument?->toPayload(),
            'customerId' => $this->customerId?->toString(),
        ];
    }
}
