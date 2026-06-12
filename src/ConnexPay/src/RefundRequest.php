<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use Techork\PaymentService\ConnexPay\Concern\ConnexPayRequestParameters;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use Money\Money;
use Omnipay\Common\Message\AbstractRequest;

/**
 * Refunds (returns) a ConnexPay sale.
 * Expects: money (Money), transactionReference (sale GUID).
 *
 * A sale that hasn't been settled yet (same-day refunds) can't be Returned —
 * the endpoint rejects it with 422 "Sale has not been settled". For that
 * case ConnexPay expects a Void of the sale instead (void accepts a SaleGuid
 * and a partial Amount), so we fall back transparently, mirroring the legacy
 * integration.
 */
final class RefundRequest extends AbstractRequest
{
    use ConnexPayRequestParameters;

    private const string SALE_NOT_SETTLED_MESSAGE = 'Sale has not been settled';

    public function getData(): array
    {
        $this->validate('money', 'transactionReference');

        /** @var Money $money */
        $money = $this->getParameter('money');

        return $this->withOrderNumber([
            'DeviceGuid' => $this->getDeviceGuid(),
            'SaleGuid' => $this->getParameter('transactionReference'),
            'Amount' => (float) $this->formatMoney($money),
        ]);
    }

    public function sendData($data): RefundResponse
    {
        try {
            $response = $this->getConnexPayClient()->post('/api/v1/returns', $data);

            return new RefundResponse($this, $response);
        } catch (BadResponseException $e) {
            if (self::isSaleNotSettled($e)) {
                return $this->voidUnsettledSale($data);
            }

            return self::failedResponse($this, $e);
        } catch (GuzzleException $e) {
            return self::failedResponse($this, $e);
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
     * @param array<string, mixed> $data the original /returns payload —
     *                                   /void accepts the same SaleGuid +
     *                                   Amount shape
     */
    private function voidUnsettledSale(array $data): RefundResponse
    {
        try {
            $response = $this->getConnexPayClient()->post('/api/v1/void', $data);

            return new RefundResponse($this, $response);
        } catch (GuzzleException $e) {
            return self::failedResponse($this, $e);
        }
    }

    private static function failedResponse(self $request, GuzzleException $e): RefundResponse
    {
        return new RefundResponse($request, [
            'wasProcessed' => false,
            'guid' => null,
            'processorResponseMessage' => $e->getMessage(),
        ]);
    }
}
