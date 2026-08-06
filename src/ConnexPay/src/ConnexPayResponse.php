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

        $url = $threeDS['acsUrl'] ?? $threeDS['AcsUrl'] ?? null;

        // Whichever of the two the step calls for. ConnexPay hands the ACS's fingerprinting
        // endpoint back in the same `acsUrl` field it later uses for the challenge endpoint, and
        // pairs it with whichever payload belongs to that step — so both are read here, and
        // which one arrived is left to whoever renders the form.
        $payload = $threeDS['cReq']
            ?? $threeDS['CReq']
            ?? $threeDS['threeDSMethodData']
            ?? $threeDS['ThreeDSMethodData']
            ?? null;

        // The protocol's own identifier, and the only one that will still mean anything when the
        // result comes back. It travels inside the base64 method payload rather than as a field
        // of its own; the sale reference this used to fall back on is ConnexPay's, not 3DS's,
        // and matching a later authentication result against it was never possible.
        $authenticationId = self::threeDSServerTransactionId($payload)
            ?? $threeDS['threeDSServerTransID']
            ?? $threeDS['ThreeDSServerTransID']
            ?? null;

        if ($url === null || $authenticationId === null) {
            return null;
        }

        return new ThreeDSChallenge(
            authenticationId: (string) $authenticationId,
            url: (string) $url,
            payload: $payload === null ? null : (string) $payload,
        );
    }

    /**
     * Dig the `threeDSServerTransID` out of a base64 3DS Method payload.
     *
     * The payload is base64 JSON — `{"threeDSMethodNotificationURL": …, "threeDSServerTransID": …}`
     * — because that is the shape the ACS is handed by the browser. Reading it here is not
     * parsing someone's internals: the field is the standard's, it is the identity the whole
     * authentication is keyed on, and no vendor publishes it anywhere more convenient.
     *
     * Null for a challenge-step payload, which is a CReq and carries no such thing.
     */
    private static function threeDSServerTransactionId(mixed $payload): ?string
    {
        if (! is_string($payload) || $payload === '') {
            return null;
        }

        $decoded = base64_decode($payload, true);

        if ($decoded === false) {
            return null;
        }

        $fields = json_decode($decoded, true);
        $id = is_array($fields) ? ($fields['threeDSServerTransID'] ?? null) : null;

        return is_string($id) && $id !== '' ? $id : null;
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
