<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Contract;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * What a provider says about a case it holds, in the terms the layer above asks in.
 *
 * ## Why the provider's status string is not one of the fields
 *
 * Stripe reports eleven-ish statuses — `needs_response`, `warning_under_review`, `prevented`,
 * `lost`, `charge_refunded` — and the layer above has a different vocabulary for the same cases
 * (won, lost, under review, expired) plus a rule about which of them still owe us work. Handing the
 * provider's own words upward would move that rule into every caller, and each of them would then
 * be deciding from a string that means "the network is deciding" at one provider and nothing at all
 * at the next. So the provider's status is *read* here and answered with the two facts a caller can
 * act on, and it is this package — which is where the provider's vocabulary is known — that reads
 * it.
 *
 * ## The facts, and why each is a fact rather than a flag
 *
 * `$awaitingResponse` is the provider still expecting an answer from us. It is the whole basis of
 * an action set: false means the case is with the network, or decided, or was never challengeable,
 * and the only honest answer is that nothing is waiting on us.
 *
 * `$concedable` is whether *closing* the case is a call this provider accepts on this one. It is
 * separate from the first because the two do not coincide: Stripe's close is documented for a
 * dispute that needs a response, while an inquiry is a different kind of case and nothing here
 * states that the same call closes it. Withholding it is the safe direction on an irreversible
 * call — an action set that offers a concession the provider refuses is a 400 in an operator's
 * face, while one that withholds it leaves the concierge able to do it by hand.
 *
 * `$cardBrand` and `$reasonCode` are the provider's own pair — Stripe's `visa` and `10.4` — carried
 * as strings because the domain's vocabulary for them is not nameable at this layer (§0.4). They
 * are what the evidence template is looked up by, so they travel together and either may be absent
 * when the provider did not state it: a payload with no brand cannot answer the question "which
 * code of which network", and the caller's job is then to surface the case without a template
 * rather than to guess one.
 *
 * `$respondBy` is the provider's own deadline, as the provider stated it. A plain
 * `DateTimeImmutable` — the instant is the whole of it, at this layer and in the aggregate alike.
 *
 * ## Awaiting a response with no deadline is refused rather than read as "no action"
 *
 * A case the provider still waits on has a deadline — that is what waiting means — and the layer
 * above cannot describe a response task without one. A payload that omits it is therefore
 * malformed, and this constructor refuses it loudly instead of handing up a reading that would
 * either invent a date or lose the action entirely. Silence in that direction reports "nothing to
 * do here" about a case that is about to be lost by default, which is the one mistake the whole
 * action model exists to prevent.
 */
final readonly class DisputeCaseReading
{
    public function __construct(
        public bool $awaitingResponse,
        public bool $concedable,
        public ?string $cardBrand,
        public ?string $reasonCode,
        public ?DateTimeImmutable $respondBy,
    ) {
        ! $awaitingResponse || $respondBy !== null || throw new InvalidArgumentException(
            'A case the provider is still waiting on must carry the deadline it is waiting until. '
            . 'Without one there is no response task to describe, and reporting the case as having '
            . 'nothing open would say it is not waiting on us — which is exactly what it is doing.',
        );

        ! $concedable || $awaitingResponse || throw new InvalidArgumentException(
            'A case that can be conceded is a case the provider is waiting on: closing it is an '
            . 'answer to the case. A reading that concedes one the provider has already decided '
            . 'would offer an irreversible call on a case that is no longer open.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'awaitingResponse' => $this->awaitingResponse,
            'concedable' => $this->concedable,
            'cardBrand' => $this->cardBrand,
            'reasonCode' => $this->reasonCode,
            'respondBy' => $this->respondBy?->format(DATE_ATOM),
        ];
    }
}
