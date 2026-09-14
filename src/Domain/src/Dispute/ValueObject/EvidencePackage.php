<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\ValueObject;

use InvalidArgumentException;
use Techork\PaymentService\Common\ValueObject\CardBrand;

/**
 * The evidence assembled for one submission on one dispute.
 *
 * This is what a submission port accepts — the whole of what we are answering with, in
 * one object, so that an adapter never has to be handed the facts piecemeal and never has
 * to guess which ones belong to the case it is answering.
 *
 * **The pair is part of the package, not of the call.** The brand and the raw reason code
 * are what decide whether a set of evidence is complete ({@see EvidenceRequirements::missingFrom()}),
 * and a package that did not carry them could be judged against the wrong template and
 * pass. The pair is also matched verbatim, for the reason the table is keyed verbatim:
 * an unrecognised reason code is a gap to surface, never a near-match to accept.
 *
 * **One item per type.** A type names a fact, and a fact arriving twice is either the same
 * fact duplicated or two documents where somebody had to decide which one answers the
 * network's question. The second is a decision the collection stage owes, so a duplicate
 * is rejected here rather than resolved by keeping whichever came last.
 *
 * The package may hold system-supplied types as well as project-supplied ones: the AVS
 * result, the 3DS outcome and the buyer's IP are submitted to the network as evidence
 * like any other, and they are ours to attach without asking anyone. What this object
 * does not do is decide what is still missing — that is the requirement table's question
 * and it is asked from there, once, for both adapters.
 */
final readonly class EvidencePackage
{
    /** @var list<EvidenceItem> */
    private array $items;

    /**
     * Any array of items is accepted and normalised to a list, rather than the list being
     * demanded of the caller: a project assembling evidence into a keyed array has done
     * nothing wrong, and {@see self::items()} promises a list either way.
     *
     * @param array<array-key, EvidenceItem> $items
     */
    public function __construct(
        public CardBrand $cardBrand,
        public string $reasonCode,
        array $items = [],
    ) {
        $reasonCode !== '' || throw new InvalidArgumentException('An evidence package must name the reason code it answers');

        $this->items = self::assertNoDuplicateFacts($items);
    }

    /**
     * The same package with one more item. Immutable, so a half-built package cannot be
     * submitted by a caller still holding a reference to it.
     */
    public function with(EvidenceItem $item): self
    {
        return new self($this->cardBrand, $this->reasonCode, [...$this->items, $item]);
    }

    /** @return list<EvidenceItem> */
    public function items(): array
    {
        return $this->items;
    }

    public function item(EvidenceType $type): ?EvidenceItem
    {
        return array_find($this->items, static fn(EvidenceItem $item): bool => $item->type === $type);

    }

    public function has(EvidenceType $type): bool
    {
        return $this->item($type) !== null;
    }

    /**
     * @param array<array-key, EvidenceItem> $items
     *
     * @return list<EvidenceItem>
     */
    private static function assertNoDuplicateFacts(array $items): array
    {
        $seen = [];

        foreach ($items as $item) {
            array_key_exists($item->type->value, $seen) && throw new InvalidArgumentException("An evidence package carries one item per fact; {$item->type->value} arrived twice");

            $seen[$item->type->value] = true;
        }

        return array_values($items);
    }
}
