<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay\Webhook\Handler;

use ArrayObject;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;
use Techork\PaymentService\Gateway\Webhook\Contract\WebhookEventHandler;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayCancellationRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;

/**
 * ConnexPay `sale.card.auth.voided`. Cancels the matching PaymentIntent
 * — typically from a dashboard-initiated void.
 *
 * @implements WebhookEventHandler<ArrayObject>
 */
final readonly class SaleVoidedHandler implements WebhookEventHandler
{
    public function __construct(
        private TransactionIdResolver $resolver,
        private GatewayCancellationRecorder $recorder,
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

        return match ($this->recorder->onGatewayCancellation($paymentIntentId)) {
            RecorderOutcome::Applied => HandlerOutcome::Processed,
            RecorderOutcome::Skipped => HandlerOutcome::Skipped,
            RecorderOutcome::NotFound => HandlerOutcome::Delay,
        };
    }
}
