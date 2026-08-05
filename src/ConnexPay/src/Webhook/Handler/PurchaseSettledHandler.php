<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay\Webhook\Handler;

use ArrayObject;
use DateTimeImmutable;
use Override;
use Techork\PaymentService\ConnexPay\Webhook\ServiceFeeFetcher;
use Techork\PaymentService\Gateway\Contract\VirtualCardReferenceRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\WebhookEventHandler;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayFeeRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;

/**
 * ConnexPay `purchase.card.auth.settled`. The virtual-card purchase has
 * settled at the recipient processor — the fee ConnexPay booked is finalized;
 * we fetch it from the Purchases API and write it onto the local VC
 * row.
 *
 * Resolving our internal `virtual_card.id` from the gateway-side
 * `cardGuid` goes through the existing `gateway_references` table
 * populated at issuance time. The current
 * {@see \Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver}
 * contract resolves PI / Refund only — for VC we expose
 * {@see VirtualCardReferenceRepository}
 * directly through the constructor.
 *
 * @implements WebhookEventHandler<ArrayObject>
 */
final readonly class PurchaseSettledHandler implements WebhookEventHandler
{
    public function __construct(
        private VirtualCardReferenceRepository $vcReferenceRepository,
        private GatewayFeeRecorder $feeRecorder,
        private ServiceFeeFetcher $feeFetcher,
    ) {}

    #[Override]
    public function __invoke(object $event, GatewayId $gatewayId): HandlerOutcome
    {
        /** @var ArrayObject $event */
        $payload = $event->getArrayCopy();

        $cardGuid = (string) ($payload['cardGuid'] ?? $payload['guid'] ?? '');
        if ($cardGuid === '') {
            return HandlerOutcome::Skipped;
        }

        $virtualCardId = $this->vcReferenceRepository->findVirtualCardId($gatewayId, $cardGuid);
        if ($virtualCardId === null) {
            return HandlerOutcome::Delay;
        }

        $fee = $this->feeFetcher->fetchPurchaseFee($gatewayId, $cardGuid);
        if ($fee === null) {
            return HandlerOutcome::Skipped;
        }

        return match ($this->feeRecorder->onVirtualCardFee($gatewayId, $virtualCardId, $fee, new DateTimeImmutable)) {
            RecorderOutcome::Applied => HandlerOutcome::Processed,
            RecorderOutcome::Skipped => HandlerOutcome::Skipped,
            RecorderOutcome::NotFound => HandlerOutcome::Delay,
        };
    }
}
