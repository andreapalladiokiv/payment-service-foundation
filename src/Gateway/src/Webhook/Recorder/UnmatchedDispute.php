<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Webhook\Recorder;

use DateTimeImmutable;
use InvalidArgumentException;
use Money\Money;
use Techork\PaymentService\Common\ValueObject\CardBrand;

/**
 * A case we could not attach to any payment of ours, reported so that it is not lost.
 *
 * ConnexPay only, and it is the exception that keeps their poller honest. A case is matched to a
 * payment by the sale guid first and only then by `OrderNumber` or `Arn` — the guid being the one
 * key a payment's reference row ordinarily holds — and a case that matches none of them is
 * **not dropped and not retried forever**: it goes out through
 * {@see GatewayDisputeRecorder::onUnmatchedDispute()} with everything we know about it, and its
 * key is stored like any other so the next poll does not report it again. Without this the poller
 * would silently lose cases, which is the same failure as never reading the resolved-cases cursor
 * and just as hard to notice.
 *
 * **Why a case matches nothing, and which fields it then carries, is a premise here rather than a
 * finding.** What this class exists for is one fact: a case can be surfaced with no payment of ours
 * at the end of it — the references it offers resolve to nothing, or the event arrives before the
 * payment it names has been recorded. What ConnexPay states on a case, and which of those fields
 * are absent in practice, is unconfirmed pending captured payloads (F0): the examples this tree
 * holds are written from documentation rather than recorded, and provider semantics are never
 * asserted from our own side.
 *
 * A webhook delivery cannot reach here: Stripe and Nuvei hand us a delivery that either names a
 * payment or does not, and the handler draws that line with `Skipped` and `Delay` before a recorder
 * is ever called.
 *
 * ## Identity is required; description is best effort
 *
 * The reference and the event key are refused when blank — they are the identity of the case and of
 * the delivery, and an empty one turns "report this once" into "report this forever" or into
 * nothing at all. Everything else may be null, because the entire point of this object is to surface
 * a case from a payload that is missing something: a reason code, an amount or a deadline that is
 * absent is exactly the kind of gap an operator has to look at rather than a reason to drop the
 * case. `$cardBrand` and `$reasonCode` are not nullable because a case missing either never reaches
 * this constructor: ConnexPay's poller refuses one with no reason code, or a brand outside the four
 * it maps, as unrepresentable rather than reporting it. That is a fact about our own reader rather
 * than a claim about what the provider states, which is the kind of statement this class can be
 * built on.
 *
 * `$saleGuid` is the provider's own guid for the sale the case is against — the reference a payment
 * of ours is recorded under, and the key the poller matches first. It is worth carrying *because*
 * it did not resolve: an unmatched case is one whose guid names no payment of ours, which points at
 * a sale we never processed rather than at a reference the provider left out, and it is the one
 * field here an operator can search the provider's own portal by to find out which. Null means the
 * payload stated none.
 */
final readonly class UnmatchedDispute
{
    public function __construct(
        public string $gatewayDisputeRef,
        public CardBrand $cardBrand,
        public string $reasonCode,
        public string $providerEventKey,
        public DateTimeImmutable $observedAt,
        public ?string $familyRef = null,
        public ?string $orderNumber = null,
        public ?string $arn = null,
        public ?string $saleGuid = null,
        public ?string $stageCode = null,
        public ?string $statusCode = null,
        public ?Money $disputedAmount = null,
        public ?DateTimeImmutable $responseDueAt = null,
    ) {
        trim($gatewayDisputeRef) !== '' || throw new InvalidArgumentException(
            'An unmatched dispute arrived with no provider reference. It is the case an operator '
            . 'is being sent to look at and the key the poller stores, so an empty one would name '
            . 'nothing and be re-reported on every poll.',
        );

        trim($providerEventKey) !== '' || throw new InvalidArgumentException(
            'An unmatched dispute arrived with no provider event key. It is how the caller '
            . 'recognises the case it has already reported, so an empty one would surface the same '
            . 'case to an operator on every poll.',
        );
    }
}
