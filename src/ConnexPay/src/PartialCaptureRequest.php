<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use GuzzleHttp\Exception\GuzzleException;

/**
 * Captures less than the authorized amount — ConnexPay has no native
 * primitive for that ("You can only capture the original amount that was
 * authorized", https://docs.connexpay.com/docs/auth-and-capture), so this
 * request voids the AuthOnly and runs a fresh sale for the smaller amount
 * with the original instrument, exactly like the legacy integration.
 *
 * Expects everything {@see PurchaseRequest} needs (money = the partial
 * amount, instrument, gateway, …) plus `transactionReference` (the AuthOnly
 * guid to void).
 *
 * Failure semantics match the legacy two-step: a failed void leaves the
 * auth intact and reports a failed capture; a failed sale after a
 * successful void also reports a failed capture, with the hold already
 * released (the retry simply runs a new sale — the void of an already
 * voided auth never happens because the stored reference is only replaced
 * on success).
 */
final class PartialCaptureRequest extends PurchaseRequest
{
    public function getData(): array
    {
        $this->validate('transactionReference');

        return parent::getData();
    }

    public function sendData($data): PurchaseResponse
    {
        try {
            $this->getConnexPayClient()->post('/api/v1/void', [
                'DeviceGuid' => $this->getDeviceGuid(),
                'AuthOnlyGuid' => $this->getParameter('transactionReference'),
            ]);
        } catch (GuzzleException $e) {
            return new PurchaseResponse($this, [
                'wasProcessed' => false,
                'guid' => null,
                'processorResponseMessage' => "Void before partial capture failed: {$e->getMessage()}",
            ]);
        }

        return parent::sendData($data);
    }
}
