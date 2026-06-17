<?php

declare(strict_types=1);

namespace Techork\PaymentService\Revolut;

use GuzzleHttp\Exception\GuzzleException;
use Omnipay\Common\Message\AbstractRequest;
use Techork\PaymentService\Revolut\Concern\RevolutRequestParameters;

/**
 * Terminates an issued virtual card permanently.
 *
 * DELETE /api/1.0/cards/{cardId} — returns 204 No Content on success.
 * Requires `transactionReference` (the card id).
 */
final class TerminateCardRequest extends AbstractRequest
{
    use RevolutRequestParameters;

    public function getData(): array
    {
        $this->validate('transactionReference');

        return [];
    }

    public function sendData($data): TerminateCardResponse
    {
        $cardId = (string) $this->getTransactionReference();

        try {
            $this->getRevolutClient()->delete("/api/1.0/cards/{$cardId}");
        } catch (GuzzleException $e) {
            return new TerminateCardResponse($this, [
                'cardGuid' => $cardId,
                'terminated' => false,
                'error' => $e->getMessage(),
            ]);
        }

        return new TerminateCardResponse($this, [
            'cardGuid' => $cardId,
            'terminated' => true,
        ]);
    }
}
