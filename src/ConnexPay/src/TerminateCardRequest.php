<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use GuzzleHttp\Exception\GuzzleException;
use Omnipay\Common\Message\AbstractRequest;
use Techork\PaymentService\ConnexPay\Concern\ConnexPayRequestParameters;

/**
 * Terminates a virtual card via ConnexPay Purchases API.
 *
 * POST /api/v1/TerminateCard/{cardGuid}
 * Base URL: sandboxpurchasesapi.connexpay.com (sandbox) / purchasesapi.connexpay.com (production)
 */
final class TerminateCardRequest extends AbstractRequest
{
    use ConnexPayRequestParameters;

    public function getData(): array
    {
        $this->validate('transactionReference');

        return [];
    }

    public function sendData($data): TerminateCardResponse
    {
        $cardGuid = $this->getParameter('transactionReference');

        try {
            $response = $this->getConnexPayClient()->post("/api/v1/TerminateCard/{$cardGuid}", $data);

            return new TerminateCardResponse($this, [
                'terminated' => true,
                'cardGuid' => $cardGuid,
                ...($response ?? []),
            ]);
        } catch (GuzzleException $e) {
            return new TerminateCardResponse($this, [
                'terminated' => false,
                'cardGuid' => $cardGuid,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
