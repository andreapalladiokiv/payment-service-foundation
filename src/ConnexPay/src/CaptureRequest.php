<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use Override;
use Techork\PaymentService\ConnexPay\Concern\ConnexPayRequestParameters;
use GuzzleHttp\Exception\GuzzleException;
use Omnipay\Common\Message\AbstractRequest;

/**
 * Captures a previously authorized ConnexPay transaction.
 * Expects: transactionReference (auth GUID).
 */
final class CaptureRequest extends AbstractRequest
{
    use ConnexPayRequestParameters;

    #[Override]
    public function getData(): array
    {
        $this->validate('transactionReference');

        return $this->withIdentifiers([
            'DeviceGuid' => $this->getDeviceGuid(),
            'AuthOnlyGuid' => $this->getParameter('transactionReference'),
            'ConnexPayTransaction' => [
                'ExpectedPayments' => 1
            ]
        ]);
    }

    #[Override]
    public function sendData($data): CaptureResponse
    {
        try {
            $response = $this->getConnexPayClient()->post('/api/v1/Captures', $data);

            // ConnexPay nests the captured sale under "sale". The sale's GUID
            // (not the capture's GUID) is what subsequent Returns/Void calls
            // expect, so we unwrap the envelope and treat the sale as the
            // primary response.
            return new CaptureResponse($this, $response['sale'] ?? $response);
        } catch (GuzzleException $e) {
            return new CaptureResponse($this, [
                'wasProcessed' => false,
                'guid' => null,
                'processorResponseMessage' => $e->getMessage(),
            ]);
        }
    }
}
