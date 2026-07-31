<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay\Webhook;

use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;

/**
 * Which payment intent a ConnexPay sale webhook belongs to.
 *
 * A card-data sale is easy: `POST /api/v1/sales` answers synchronously with the
 * sale guid, the port stores guid → intent, and the webhook arrives carrying
 * that same guid.
 *
 * A hosted-page sale has no guid to store. `POST /api/v1/HostedPaymentPageRequests`
 * returns only a short-lived token — the sale itself does not exist until the
 * buyer pays on ConnexPay's page — so the webhook is the first time we ever see
 * its guid, and looking it up finds nothing. What survives the redirect is
 * `OrderNumber`: we put the payment intent id there on the way out
 * ({@see \Techork\PaymentService\ConnexPay\Concern\ConnexPayRequestParameters::withOrderNumber})
 * and ConnexPay echoes it on the sale message as the "client provided
 * transaction identifier".
 *
 * The UUID shape check is the entire trust boundary. `orderNumber` is
 * attacker-controlled webhook input, so anything that is not shaped like one of
 * our aggregate ids has to resolve to nothing rather than to somebody else's
 * intent. A well-formed id we do not hold still ends as `NotFound` at the
 * recorder, which handlers turn into a retry rather than a silent drop.
 */
final class SaleCorrelation
{
    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function resolve(
        TransactionIdResolver $resolver,
        GatewayId $gatewayId,
        string $saleGuid,
        array $payload,
    ): self {
        $byGuid = $saleGuid === '' ? null : $resolver->resolvePaymentIntent($gatewayId, $saleGuid);

        if ($byGuid !== null) {
            return new self($byGuid, viaOrderNumber: false);
        }

        return new self(self::orderNumber($payload), viaOrderNumber: true);
    }

    private function __construct(
        public ?string $paymentIntentId,
        /**
         * True when the intent was recovered from `OrderNumber` because no
         * stored reference matched the guid — which is itself the signal that
         * this sale was created on a hosted page rather than by us, and so has
         * never been confirmed on our side.
         */
        public bool $viaOrderNumber,
    ) {}

    public function found(): bool
    {
        return $this->paymentIntentId !== null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function orderNumber(array $payload): ?string
    {
        $raw = (string) ($payload['orderNumber'] ?? $payload['OrderNumber'] ?? '');

        // Mirrors the suffix stripping applied when the value was sent, so a
        // ":capture" / ":cancel" scoped id still lands on its aggregate.
        $candidate = (string) preg_replace('/:(?:capture|cancel)$/', '', $raw);

        return preg_match(self::UUID_PATTERN, $candidate) === 1 ? $candidate : null;
    }
}
