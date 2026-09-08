<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use Techork\PaymentService\ConnexPay\Concern\BuildsConnexPayPayload;
use Techork\PaymentService\ConnexPay\Concern\MapsConnexPayOutcome;
use Techork\PaymentService\Gateway\Command\RefundCommand;
use Techork\PaymentService\Gateway\Contract\GatewayResult;

/**
 * Returns money that was taken — `POST /api/v1/returns`.
 *
 * A sale that hasn't been settled yet (same-day refunds) can't be Returned —
 * the endpoint rejects it with 422 "Sale has not been settled". For that
 * case ConnexPay expects a Void of the sale instead (void accepts a SaleGuid
 * and a partial Amount), so we fall back transparently, mirroring the legacy
 * integration.
 */
final class Refund
{
    use BuildsConnexPayPayload;
    use MapsConnexPayOutcome;

    private const string RETURNS_PATH = '/api/v1/returns';

    private const string VOID_PATH = '/api/v1/void';

    private const string SALE_NOT_SETTLED_MESSAGE = 'Sale has not been settled';

    public function __construct(
        private readonly ConnexPaySettings $settings,
        private readonly RefundCommand $command,
        private readonly ConnexPayHttpClientInterface $client,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->withIdentifiers([
            'DeviceGuid' => $this->settings->deviceGuid,
            'SaleGuid' => $this->command->transactionReference,
            'Amount' => (float) $this->settings->formatAmount($this->command->amount),
        ], $this->command->clientUniqueId);
    }

    public function refund(): GatewayResult
    {
        $payload = $this->payload();

        try {
            return $this->outcome($this->client->post(self::RETURNS_PATH, $payload));
        } catch (BadResponseException $e) {
            return self::isSaleNotSettled($e)
                ? $this->voidUnsettledSale($payload)
                : GatewayResult::failed($e->getMessage());
        } catch (GuzzleException $e) {
            return GatewayResult::failed($e->getMessage());
        }
    }

    private static function isSaleNotSettled(BadResponseException $e): bool
    {
        if ($e->getResponse()->getStatusCode() !== 422) {
            return false;
        }

        $body = json_decode((string) $e->getResponse()->getBody(), true);

        return ($body['message'] ?? null) === self::SALE_NOT_SETTLED_MESSAGE;
    }

    /**
     * @param array<string, mixed> $payload the original /returns body —
     *                                      /void accepts the same SaleGuid +
     *                                      Amount shape
     */
    private function voidUnsettledSale(array $payload): GatewayResult
    {
        try {
            return $this->outcome($this->client->post(self::VOID_PATH, $payload));
        } catch (GuzzleException $e) {
            return GatewayResult::failed($e->getMessage());
        }
    }
}
