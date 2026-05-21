<?php

declare(strict_types=1);

namespace Techork\PaymentService\Conferma;

use GuzzleHttp\Exception\GuzzleException;
use Omnipay\Common\Message\AbstractRequest;
use Techork\PaymentService\Conferma\Concern\ConfermaRequestParameters;

/**
 * Cancels an outstanding deployment (virtual card).
 *
 * POST /deployments/v1/deployments/{id}/cancel — empty body. Confirm
 * with Conferma integration team whether a reason payload is required.
 */
final class TerminateCardRequest extends AbstractRequest
{
    use ConfermaRequestParameters;

    public function getData(): array
    {
        $this->validate('transactionReference');

        return [];
    }

    public function sendData($data): TerminateCardResponse
    {
        $cardGuid = (string) $this->getParameter('transactionReference');

        try {
            $response = $this->getConfermaClient()->post("/deployments/v1/deployments/{$cardGuid}/cancel", $data);

            return new TerminateCardResponse($this, [
                'terminated' => true,
                'cardGuid' => $cardGuid,
                ...$response,
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
