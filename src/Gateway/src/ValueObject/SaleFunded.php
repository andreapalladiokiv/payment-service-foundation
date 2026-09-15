<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\ValueObject;

use Override;
use Techork\PaymentService\Gateway\Contract\GatewayTransactionRepository;

/**
 * The card draws on money already taken from a cardholder.
 *
 * One required field, because one is all every sale-funding acquirer shares: the sale has a
 * reference and the card points at it.
 *
 * **It is the ACQUIRER's reference, not our payment intent id, and that is the same boundary the
 * other four commands sit on.** `CaptureCommand`, `RefundCommand` and `CancelCommand` are all
 * vendor-facing in exactly this way: an adapter holds the {@see GatewayTransactionRepository},
 * turns our id into the acquirer's reference, refuses in its own voice when there is no row, and
 * only then builds the command (`Laravel\Port\CaptureAdapter::capture()`).
 * A card command carrying a `PaymentIntentId` while its four siblings carry a reference is what
 * would make the card path special; carrying the reference is what keeps it ordinary.
 *
 * What IS different about cards is where that adapter lives, and it has a cause rather than being
 * an oversight: the adapters in `Laravel/src/Port/` each back a domain port of an event-sourced
 * aggregate, and the card deliberately has no aggregate — so its resolving adapter is the
 * application's own card composition root instead of a port implementation. Same shape, same
 * repository, same refusal; a different home because there is no port interface for it to
 * implement.
 *
 * The optional hint is the escape hatch for acquirers that name the same sale twice; see
 * {@see SaleFundingHint} for why it is opaque here and typed at the far end.
 */
final readonly class SaleFunded implements CardFunding
{
    public function __construct(
        public string $transactionReference,
        public ?SaleFundingHint $hint = null,
    ) {}

    #[Override]
    public function model(): CardFundingModel
    {
        return CardFundingModel::Sale;
    }

    /**
     * The hint is named by type and never unwrapped: whatever is inside it belongs to one gateway,
     * and a log line shared by all of them is the wrong place to spell it out.
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function toLogContext(): array
    {
        return [
            'fundingModel' => $this->model()->value,
            'transactionReference' => $this->transactionReference,
            'fundingHint' => $this->hint === null ? null : $this->hint::class,
        ];
    }
}
