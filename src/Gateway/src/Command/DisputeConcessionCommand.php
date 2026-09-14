<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Command;

use InvalidArgumentException;
use Money\Money;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Concede a case, in whole or — where the provider takes one — in part.
 *
 * ## The name, and the one it must not be confused with
 *
 * `Domain\Dispute\Command\AcceptDisputeCommand` is the aggregate's own command: it records our
 * decision to stop fighting, which is why the case reads `ACCEPTED` rather than the `lost` the
 * provider reports. This is the request to a provider to close the case, one layer underneath, and
 * naming it `AcceptDisputeCommand` here would put the same two words on the aggregate's fact and on
 * the call that may not have happened. "Concede" is the act at the provider; "accept" stays the
 * domain's word for the decision.
 *
 * ## Irreversible, and what that means for this object
 *
 * Stripe's `POST /v1/disputes/:id/close` moves the case to `lost` and there is no call that takes
 * it back. Requiring an operator's explicit confirmation before this is dispatched is the
 * application's job (A2) — the adapter does not prompt and cannot, holding no policy and no screen.
 * What the layers below owe in exchange is that nothing here concedes MORE than was asked: a
 * provider that cannot honour a partial amount refuses it rather than closing the whole case, since
 * the difference is the entire disputed sum and the call cannot be undone.
 *
 * ## Why the partial amount rides here rather than in an action
 *
 * What we are willing to give up is decided while making the call and cannot be known by a read
 * that offered the action — so it is a field of the request, exactly as it is a field of the
 * domain's `AcceptDisputeRequest`, and it is null for the acceptance Stripe has: a close concedes
 * the whole case.
 */
final readonly class DisputeConcessionCommand
{
    /**
     * @param  string  $disputeReference  the provider's own reference for the case, verbatim and
     *   unnormalised — see {@see DisputeEvidenceCommand} for why it is not touched
     * @param  ?Money  $partialAmount  the part to concede, or null for the whole case. Non-null is
     *   a request only some providers can be given; the ones that cannot must refuse it.
     * @param  ?string  $clientUniqueId  the caller's key for this attempt, where it has one, which
     *   becomes the provider's idempotency key
     */
    public function __construct(
        public GatewayId $gatewayId,
        public string $disputeReference,
        public ?Money $partialAmount = null,
        public ?string $clientUniqueId = null,
    ) {
        trim($disputeReference) !== ''
            || throw new InvalidArgumentException(
                'A concession must name the case it concedes; an empty reference closes nothing '
                . 'and reads as an attempt to.',
            );
    }

    /** Whether less than the whole disputed amount is being given up. */
    public function isPartial(): bool
    {
        return $this->partialAmount !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'gatewayId' => $this->gatewayId->toString(),
            'disputeReference' => $this->disputeReference,
            'partialAmount' => $this->partialAmount,
            'clientUniqueId' => $this->clientUniqueId,
        ];
    }
}
