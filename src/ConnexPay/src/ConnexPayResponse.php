<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use Omnipay\Common\Message\AbstractResponse;
use Override;
use Techork\PaymentService\Common\Contract\Challenge;
use Techork\PaymentService\Common\ValueObject\Challenge\ThreeDSChallenge;
use Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult;
use Techork\PaymentService\Gateway\Contract\CardChecksProvider;
use Techork\PaymentService\Gateway\Contract\ChallengeProvider;
use Techork\PaymentService\Gateway\Contract\TransactionMetadataProvider;

class ConnexPayResponse extends AbstractResponse implements CardChecksProvider, ChallengeProvider, TransactionMetadataProvider
{
    #[Override]
    public function isSuccessful(): bool
    {
        return ($this->data['wasProcessed'] ?? false) === true;
    }

    #[Override]
    public function getTransactionReference(): ?string
    {
        return $this->data['guid'] ?? null;
    }

    /**
     * The incoming transaction code is the merchant-facing id the legacy API
     * contract exposes as `acquirer_id` — it exists only in the sale /
     * capture response body (capture nests it under `sale`, which
     * {@see CaptureRequest::sendData()} already unwraps), so it must be
     * persisted with the reference or it's gone until a backfill.
     */
    #[Override]
    public function getTransactionMetadata(): array
    {
        $code = $this->data['connexPayTransaction']['incomingTransCode']
            ?? $this->data['ConnexPayTransaction']['IncomingTransCode']
            ?? null;

        return $code === null || $code === ''
            ? []
            : ['incoming_transaction_code' => (string) $code];
    }

    #[Override]
    public function getMessage(): ?string
    {
        return $this->data['processorResponseMessage'] ?? $this->data['status'] ?? null;
    }

    #[Override]
    public function getChallenge(): ?Challenge
    {
        $threeDS = $this->data['threeDSecure'] ?? $this->data['ThreeDSecure'] ?? null;

        if ($threeDS === null) {
            return null;
        }

        $status = $threeDS['authenticationStatus'] ?? $threeDS['AuthenticationStatus'] ?? null;
        if ($status !== 'Challenge') {
            return null;
        }

        $acsUrl = $threeDS['acsUrl'] ?? $threeDS['AcsUrl'] ?? null;
        $reference = $this->getTransactionReference();

        if ($acsUrl === null || $reference === null) {
            return null;
        }

        return new ThreeDSChallenge(
            transactionId: $reference,
            acsUrl: $acsUrl,
            creq: $threeDS['cReq'] ?? $threeDS['CReq'] ?? null,
        );
    }

    #[Override]
    public function getAddressLineCheck(): ?CheckResult
    {
        $avs = $this->avsLetter();

        return $avs === null ? null : ConnexPaySchemeChecks::avsToLineAndPostal($avs)[0];
    }

    #[Override]
    public function getPostalCodeCheck(): ?CheckResult
    {
        $avs = $this->avsLetter();

        return $avs === null ? null : ConnexPaySchemeChecks::avsToLineAndPostal($avs)[1];
    }

    #[Override]
    public function getCvcCheck(): ?CheckResult
    {
        $cvv = $this->data['cvvVerificationCode'] ?? $this->data['CvvVerificationCode'] ?? null;

        if ($cvv === null || $cvv === '') {
            return null;
        }

        return ConnexPaySchemeChecks::cvvToCheckResult((string) $cvv);
    }

    protected function avsLetter(): ?string
    {
        $avs = $this->data['addressVerificationCode'] ?? $this->data['AddressVerificationCode'] ?? null;

        return $avs === null || $avs === '' ? null : (string) $avs;
    }
}
