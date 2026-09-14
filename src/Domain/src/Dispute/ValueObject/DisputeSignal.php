<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\ValueObject;

use DateTimeImmutable;
use DateTimeInterface;
use Techork\PaymentService\Domain\Dispute\Exception\InvalidDispute;

/**
 * One delivery of an external fact about a case: what the provider said, when it said it, and
 * the key that makes a repeat delivery recognisable as one.
 *
 * Every method on the aggregate that records something a provider said takes one of these, because
 * the idempotency key is a property of the *delivery* rather than of the state: which delivery of
 * which fact this is, as the provider itself keys it.
 *
 * ## The gateway is not on this object, and was never part of the key
 *
 * It used to be a field here, and the key was prefixed with it. The three reasons it is not all say
 * the same thing: the value belongs to the layer that already holds it, and this package has no use
 * for it.
 *
 *  - **The aggregate cannot use it.** A case is raised at one provider and belongs to one gateway
 *    account for its whole life, so within one event stream a gateway component is a *constant*: it
 *    can never be the difference between two keys the guard compares.
 *    {@see \Techork\PaymentService\Domain\Dispute\DisputeAggregate::hasAlreadyApplied()} compares a
 *    key to the most recent one applied *in the same stream*, which is the only comparison there is.
 *  - **It wrote provider-account identity into the domain's trail.** The key is not only compared,
 *    it is recorded — every dispute event carries it as `signal_key` — so prefixing it put a gateway
 *    account of ours, durably, inside the event stream of a domain that never reads it back.
 *  - **It could not catch the one thing it looks like it guards.** A delivery mis-addressed to the
 *    wrong case arrives carrying the *other* account's id, forms a key that matches nothing, and is
 *    applied as a new fact; the prefix makes that failure quieter, not louder. Choosing the stream
 *    is the recorder's job, and it is handed the gateway to choose it with.
 *
 * Where the gateway does belong is where it already is: `GatewayIdMessageDecorator` writes it onto
 * every event's headers, the recorder receives it as an argument, and `gateway_references.gateway_id`
 * records it durably against the case. A `Gateway\ValueObject\GatewayId` could not appear here in
 * any case — the arch hierarchy in `tests/Arch/PackageHierarchyTest.php` has Domain reaching Common
 * and nothing else — and the value is opaque in this package either way.
 *
 * The case's identity is our own {@see DisputeId} and is not carried here at all — the aggregate
 * comparing this key *is* the case (see the aggregate's
 * {@see \Techork\PaymentService\Domain\Dispute\DisputeAggregate::hasAlreadyApplied()}), and the
 * provider's reference for the case is not an identity, not part of this key, and not known to the
 * Domain: it lives in `gateway_references`, resolved by whoever needs to address the provider.
 *
 * ## `providerEventKey` is opaque, and its definition is the adapter's
 *
 * ```
 * Stripe     the webhook event.id
 * Nuvei      DisputeEventId
 * ConnexPay  the snapshot hash of the case's current state, computed inside the ConnexPay
 *            package from eleven fields in a fixed order — there is no event id to use
 * ```
 *
 * Nothing here parses it, and nothing may: ConnexPay has no event id at all and its key is a
 * hash of the case's current state, so a component that tried to interpret the value would be
 * wrong for at least one provider. What the aggregate does with it is equality against the
 * most recent one it has applied. It is a plain string because a provider's key is a plain
 * string; no value object of ours types it, here or anywhere.
 *
 * ## It may not be empty
 *
 * Refused when blank, for one reason: an empty key is a *constant*, a constant matches every later
 * delivery as readily as it matches the first, and the guard meant to suppress repeats would then
 * drop real facts on the floor while reporting nothing. It is kept untrimmed once it is not blank —
 * a provider's own value is compared, not corrected.
 */
final readonly class DisputeSignal
{
    public function __construct(
        public string $providerEventKey,
        public DateTimeImmutable $observedAt,
    ) {
        trim($providerEventKey) !== '' || throw InvalidDispute::emptyProviderEventKey();
    }

    /** @return array<string, string> */
    public function toPayload(): array
    {
        return [
            'provider_event_key' => $this->providerEventKey,
            'observed_at' => $this->observedAt->format(DateTimeInterface::ATOM),
        ];
    }

    /** @param array<string, string> $payload */
    public static function fromPayload(array $payload): self
    {
        return new self(
            $payload['provider_event_key'],
            new DateTimeImmutable($payload['observed_at']),
        );
    }
}
