<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Webhook\Recorder;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A case the networks decided, reported on its own because it can arrive on its own.
 *
 * A resolution is the provider's answer to a case — `won` or `lost` at Stripe, `FC-CLSD-MF` or
 * `FC-CLSD-CHF` at Nuvei — and it is the one fact that reaches us without necessarily being tied to
 * a PaymentIntent: it can arrive for a case whose payment intent never resolved, or in a backlog
 * import long after. That is why {@see GatewayDisputeRecorder::onDisputeResolved()} is keyed on the
 * provider's reference rather than on a PaymentIntent, and why this object carries no PaymentIntent
 * either — the reference on the call is the key.
 *
 * ## The status is a code, and only the mapper decides whether it is an outcome
 *
 * `$statusCode` is the provider's own word, unmapped, for the same reason
 * {@see DisputeSnapshot} carries its fields raw: this package may not see the domain's status enum.
 * Nothing here asserts that the code *is* a resolution — a provider says what it says, and a
 * `statusCode` naming a case still under review is the mapper's to refuse rather than to file as an
 * outcome. What the caller must not do is call this for anything else: the recorder's whole purpose
 * is the two values that mean money moved.
 *
 * `$status` is the domain's own spelling of that code, filled by the adapter that owns the
 * provider's table — the same split, for the same reason, as {@see DisputeSnapshot} documents: the
 * raw code is one provider's vocabulary (`FC-CLSD-MF` at Nuvei, `won` at Stripe) and no recorder can
 * read a status out of it. It is nullable only because this DTO is shared with providers that state
 * no code at all; a caller reporting a resolution should fill it, and the recorder refuses a
 * resolution that arrives with the code alone.
 *
 * `$familyRef` is here for the same reason it is on the snapshot — a resolution can name a case
 * whose reference changed while the family stayed the same — and stays null for providers whose
 * cases never re-reference themselves.
 */
final readonly class DisputeResolution
{
    public function __construct(
        public string $statusCode,
        public string $providerEventKey,
        public DateTimeImmutable $observedAt,
        public ?string $familyRef = null,
        public ?string $status = null,
    ) {
        trim($statusCode) !== '' || throw new InvalidArgumentException(
            'A dispute resolution arrived with no provider status code. It is the whole of the '
            . 'fact being reported — which way the case went — so an empty one would reach the '
            . 'aggregate as a resolution with nothing decided.',
        );

        trim($providerEventKey) !== '' || throw new InvalidArgumentException(
            'A dispute resolution arrived with no provider event key. It is the idempotency key '
            . 'the aggregate compares, and an empty one would suppress every later resolution of '
            . 'this case as a repeat of the first.',
        );
    }
}
