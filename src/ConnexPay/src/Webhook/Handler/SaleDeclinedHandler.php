<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay\Webhook\Handler;

use ArrayObject;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;
use Techork\PaymentService\Gateway\Webhook\Contract\WebhookEventHandler;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayFailureRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;

/**
 * ConnexPay `sale.card.auth.declined`. Records the failure on the
 * matching PaymentIntent (typically dashboard-initiated declines —
 * our own saga path already records failures inline).
 *
 * @implements WebhookEventHandler<ArrayObject>
 */
final readonly class SaleDeclinedHandler implements WebhookEventHandler
{
    public function __construct(
        private TransactionIdResolver $resolver,
        private GatewayFailureRecorder $recorder,
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

        $reason = (string) ($payload['processorMessage'] ?? $payload['processorResponseMessage'] ?? 'Sale declined at gateway');

        return match ($this->recorder->onGatewayFailure($paymentIntentId, $reason)) {
            RecorderOutcome::Applied => HandlerOutcome::Processed,
            RecorderOutcome::Skipped => HandlerOutcome::Skipped,
            RecorderOutcome::NotFound => HandlerOutcome::Delay,
        };
    }
}
