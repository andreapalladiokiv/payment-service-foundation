<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay\Webhook\Handler;

use ArrayObject;
use DateTimeImmutable;
use Money\Currency;
use Money\Money;
use Techork\PaymentService\ConnexPay\Webhook\ServiceFeeFetcher;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;
use Techork\PaymentService\Gateway\Webhook\Contract\WebhookEventHandler;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayFeeRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;

/**
 * ConnexPay `sale.card.auth.approved`. Sales on ConnexPay are
 * auto-captured (no separate auth/capture step), so this event also
 * marks the moment the processor fee is finalized — we fetch
 * `serviceFee` via {@see ServiceFeeFetcher} and forward it to the
 * recorder.
 *
 * Idempotency comes from the upstream {@see WebhookCall} unique index
 * on `(name, external_id)`; this handler doesn't dedupe itself.
 *
 * Skipped if `guid` is missing on the payload; delayed if our PI hasn't
 * been observed yet (webhook retry will pick it up once the saga side
 * has persisted the aggregate).
 *
 * @implements WebhookEventHandler<ArrayObject>
 */
final readonly class SaleApprovedHandler implements WebhookEventHandler
{
    public function __construct(
        private TransactionIdResolver $resolver,
        private GatewayFeeRecorder $feeRecorder,
        private ServiceFeeFetcher $feeFetcher,
    ) {}

    public function __invoke(object $event, GatewayId $gatewayId): HandlerOutcome
    {
        /** @var ArrayObject $event */
        $payload = $event->getArrayCopy();

        $saleGuid = (string) ($payload['guid'] ?? $payload['Guid'] ?? '');
        if ($saleGuid === '') {
            return HandlerOutcome::Skipped;
        }

        $paymentIntentId = $this->resolver->resolvePaymentIntent($gatewayId, $saleGuid);
        if ($paymentIntentId === null) {
            return HandlerOutcome::Delay;
        }

        $fee = $this->feeFetcher->fetchSaleFee($gatewayId, $saleGuid);
        if ($fee === null) {
            // Fee not present yet — retries by webhook delivery layer
            // will catch the next firing of this event for the same
            // sale; drop quietly.
            return HandlerOutcome::Skipped;
        }

        return match ($this->feeRecorder->onPaymentIntentFee($gatewayId, $paymentIntentId, $fee, new DateTimeImmutable)) {
            RecorderOutcome::Applied => HandlerOutcome::Processed,
            RecorderOutcome::Skipped => HandlerOutcome::Skipped,
            RecorderOutcome::NotFound => HandlerOutcome::Delay,
        };
    }
}
