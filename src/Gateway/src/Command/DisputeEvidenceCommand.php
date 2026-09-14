<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Command;

use InvalidArgumentException;
use Techork\PaymentService\Gateway\ValueObject\DisputeEvidenceItem;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Answer a case with evidence, and say whether the answer goes out.
 *
 * ## The name, and the one it must not be confused with
 *
 * `Domain\Dispute\Command\SubmitDisputeEvidenceCommand` is the aggregate's own command: it
 * records that our response went out. This is the request to a provider to file one, at the layer
 * underneath, and it is a different type for a different actor — which is why it is named for its
 * subject rather than for the act. A `Gateway\Command\SubmitDisputeEvidenceCommand` would be the
 * same words for two things a reader has to keep apart, and the aggregate's port is where that
 * confusion costs a recorded fact.
 *
 * ## `$submit`, and the dual control the plan asks for
 *
 * Stripe stages evidence with `submit: false` — on the dispute, visible in the dashboard,
 * invisible to the issuer — and sends it with a second call carrying `submit: true`. That pair is
 * a ready-made draft → review → submit flow and F7 says to use it rather than invent one, so the
 * flag is here rather than a second role method: the two calls differ in one argument and in
 * nothing else, and two methods would have to restate the whole command. It is `$submit` and not
 * `$stageOnly` because the provider's own parameter is `submit` and it is named the same way at
 * every layer below; the single inversion lives in the adapter that reads the domain's
 * `SubmitEvidenceRequest::$stageOnly`.
 *
 * A provider with no staging step — Nuvei files a response in one call — refuses `false` rather
 * than sending anyway. Doing the sending regardless would put a file in front of an issuer that a
 * reviewer had not approved, which is the one thing the staging flow exists to prevent.
 *
 * ## Why the whole list arrives at once
 *
 * One call carries the whole package, because that is the shape of the provider's request: Stripe
 * takes an `evidence` object with every field in it, and a submission sent field by field would be
 * several partial answers to one question. What a provider cannot take — an item with no field, two
 * files for one field — is that provider's to refuse, with its own typed exception, before it sends.
 */
final readonly class DisputeEvidenceCommand
{
    /** @var list<DisputeEvidenceItem> */
    private array $evidence;

    /**
     * @param  string  $disputeReference  the provider's own reference for the case — Stripe's
     *   `dp_…`, and at other providers the id with a `/` and a `+` in it. Opaque here: it goes
     *   into the provider's request verbatim and is never normalised, trimmed or url-decoded on
     *   the way.
     * @param  array<array-key, DisputeEvidenceItem>  $evidence  the facts to file. Normalised to a
     *   list rather than demanded as one, because a project assembling evidence into a keyed array
     *   has done nothing wrong and the provider's field order is not the caller's to decide.
     * @param  bool  $submit  whether the evidence goes to the network in this call. False stages
     *   it at the provider where the provider has a staging step, and is refused where it has none.
     * @param  ?string  $clientUniqueId  the caller's key for this attempt, where it has one. It
     *   becomes the provider's idempotency key, and it is the caller's because what counts as
     *   "the same call" is knowledge about our own retries — see `CaptureCommand` for the same
     *   field and the commit that made it typed rather than positional.
     */
    public function __construct(
        public GatewayId $gatewayId,
        public string $disputeReference,
        array $evidence,
        public bool $submit,
        public ?string $clientUniqueId = null,
    ) {
        trim($disputeReference) !== ''
            || throw new InvalidArgumentException(
                'A dispute submission must name the case it answers. The reference is the only '
                . 'thing that ties the call to a case at the provider, and an empty one would be '
                . 'sent as a request against no case at all.',
            );

        foreach ($evidence as $item) {
            $item instanceof DisputeEvidenceItem || throw new InvalidArgumentException(
                'A dispute submission carries dispute evidence items and nothing else; '
                . get_debug_type($item).' arrived in the list.',
            );
        }

        $this->evidence = array_values($evidence);
    }

    /** @return list<DisputeEvidenceItem> */
    public function evidence(): array
    {
        return $this->evidence;
    }

    public function isEmpty(): bool
    {
        return $this->evidence === [];
    }

    /**
     * The facts this submission answers with, by name.
     *
     * @return list<string>
     */
    public function types(): array
    {
        return array_map(static fn (DisputeEvidenceItem $item): string => $item->type, $this->evidence);
    }

    /**
     * What a log line may say about this call.
     *
     * The items go in as their own log context — the fact, the media type and a character count —
     * because evidence is a customer's correspondence and a signed delivery note, and neither
     * belongs in a log. `types()` is what an operator needs to recognise the submission; the bytes
     * are not.
     *
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'gatewayId' => $this->gatewayId->toString(),
            'disputeReference' => $this->disputeReference,
            'evidence' => array_map(
                static fn (DisputeEvidenceItem $item): array => $item->toLogContext(),
                $this->evidence,
            ),
            'submit' => $this->submit,
            'clientUniqueId' => $this->clientUniqueId,
        ];
    }
}
