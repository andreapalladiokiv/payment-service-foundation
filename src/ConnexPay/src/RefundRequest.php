<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use Techork\PaymentService\ConnexPay\Concern\ConnexPayRequestParameters;
use GuzzleHttp\Exception\GuzzleException;
use Money\Money;
use Omnipay\Common\Message\AbstractRequest;

/**
 * Refunds (returns) a settled ConnexPay sale.
 * Expects: money (Money), transactionReference (sale GUID).
 */
final class RefundRequest extends AbstractRequest
{
    use ConnexPayRequestParameters;

    public function getData(): array
    {
        $this->validate('money', 'transactionReference');

        /** @var Money $money */
        $money = $this->getParameter('money');

        $data = [
            'DeviceGuid' => $this->getDeviceGuid(),
            'SaleGuid' => $this->getParameter('transactionReference'),
            'Amount' => (float) $this->formatMoney($money),
        ];

        if ($this->getClientUniqueId() !== null) {
            $data['OrderNumber'] = $this->getClientUniqueId();
        }

        return $data;
    }

    public function sendData($data): RefundResponse
    {
        try {
            $response = $this->getConnexPayClient()->post('/api/v1/returns', $data);

            return new RefundResponse($this, $response);
        } catch (GuzzleException $e) {
            return new RefundResponse($this, [
                'wasProcessed' => false,
                'guid' => null,
                'processorResponseMessage' => $e->getMessage(),
            ]);
        }
    }
}
