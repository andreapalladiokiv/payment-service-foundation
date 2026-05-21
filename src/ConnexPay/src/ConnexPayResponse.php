<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use Omnipay\Common\Message\AbstractResponse;
use Techork\PaymentService\Common\Contract\Challenge;
use Techork\PaymentService\Common\ValueObject\Challenge\ThreeDSChallenge;
use Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult;
use Techork\PaymentService\Gateway\Contract\CardChecksProvider;
use Techork\PaymentService\Gateway\Contract\ChallengeProvider;

class ConnexPayResponse extends AbstractResponse implements CardChecksProvider, ChallengeProvider
{
    public function isSuccessful(): bool
    {
        return ($this->data['wasProcessed'] ?? false) === true;
    }

    public function getTransactionReference(): ?string
    {
        return $this->data['guid'] ?? null;
    }

    public function getMessage(): ?string
    {
        return $this->data['processorResponseMessage'] ?? $this->data['status'] ?? null;
    }

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

    public function getAddressLineCheck(): ?CheckResult
    {
        $avs = $this->avsLetter();

        return $avs === null ? null : ConnexPaySchemeChecks::avsToLineAndPostal($avs)[0];
    }

    public function getPostalCodeCheck(): ?CheckResult
    {
        $avs = $this->avsLetter();

        return $avs === null ? null : ConnexPaySchemeChecks::avsToLineAndPostal($avs)[1];
    }

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
