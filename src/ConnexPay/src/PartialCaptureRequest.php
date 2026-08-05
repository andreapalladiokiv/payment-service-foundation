<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use GuzzleHttp\Exception\GuzzleException;
use Override;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Gateway\Exception\UnsupportedInstrument;

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
 * released (the retry repeats the whole two-step — because the stored
 * reference is only replaced on success, it voids the already voided
 * AuthOnly again and only then runs the new sale; the void is not
 * skipped).
 */
final class PartialCaptureRequest extends PurchaseRequest
{
    #[Override]
    public function getData(): array
    {
        $this->validate('transactionReference');

        return parent::getData();
    }

    /**
     * Refuses what the parent now builds. Inheriting `PurchaseRequest`'s hosted
     * branch would turn a partial capture into a fresh hosted page — a redirect
     * asking the buyer to pay again — instead of settling the existing auth.
     *
     * Unreachable today (a hosted intent is `Immediate` by invariant, so it is
     * charged rather than authorized and never reaches capture), which is
     * precisely why it is worth stating: the parent's behaviour changed under
     * this class, and silence would let a later change to that invariant land
     * here as a duplicate charge.
     */
    #[Override]
    public function visitHostedPayment(HostedPayment $hosted): never
    {
        throw UnsupportedInstrument::forGateway('connexpay', 'partialCapture', $hosted);
    }

    #[Override]
    public function sendData($data): ConnexPayResponse
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
