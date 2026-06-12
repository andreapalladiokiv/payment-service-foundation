<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use GuzzleHttp\Exception\GuzzleException;
use Omnipay\Common\Message\AbstractRequest;
use Techork\PaymentService\ConnexPay\Concern\ConnexPayRequestParameters;

/**
 * Voids a previously authorized ConnexPay transaction.
 * Expects: transactionReference (auth GUID).
 */
final class VoidRequest extends AbstractRequest
{
    use ConnexPayRequestParameters;

    public function getData(): array
    {
        $this->validate('transactionReference');

        return $this->withOrderNumber([
            'DeviceGuid' => $this->getDeviceGuid(),
            'AuthOnlyGuid' => $this->getParameter('transactionReference'),
        ]);
    }

    public function sendData($data): VoidResponse
    {
        try {
            $response = $this->getConnexPayClient()->post('/api/v1/void', $data);

            return new VoidResponse($this, $response);
        } catch (GuzzleException $e) {
            return new VoidResponse($this, [
                'wasProcessed' => false,
                'guid' => null,
                'processorResponseMessage' => $e->getMessage(),
            ]);
        }
    }
}
