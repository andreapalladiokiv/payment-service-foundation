<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay\Webhook;

use Override;
use Techork\PaymentService\ConnexPay\Webhook\Handler\SaleDeclinedHandler;
use Techork\PaymentService\ConnexPay\Webhook\Handler\SaleVoidedHandler;
use Techork\PaymentService\Gateway\Webhook\Contract\WebhookSubscriber;
use Techork\PaymentService\Gateway\Webhook\HandlerRegistry;
use Techork\PaymentService\Gateway\Webhook\VerifierRegistry;

final readonly class ConnexPayWebhookSubscriber implements WebhookSubscriber
{
    private const string KIND = 'ConnexPay';

    public function __construct(
        private SignatureVerifier $verifier,
        private EventParser $parser,
        private SaleDeclinedHandler $saleDeclined,
        private SaleVoidedHandler $saleVoided,
    ) {}

    #[Override]
    public function subscribe(VerifierRegistry $verifiers, HandlerRegistry $handlers): void
    {
        $verifiers->register(self::KIND, $this->verifier, $this->parser);

        $handlers->register(self::KIND, EventParser::TYPE_SALE_AUTH_DECLINED, $this->saleDeclined);
        $handlers->register(self::KIND, EventParser::TYPE_SALE_AUTH_VOIDED, $this->saleVoided);
    }
}
