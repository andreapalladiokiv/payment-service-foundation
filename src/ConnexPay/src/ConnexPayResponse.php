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

    /**
     * The 3DS step ConnexPay is waiting on, read from the fields it actually returns.
     *
     * It used to look for `threeDSecure.acsUrl` and `cReq` behind an `authenticationStatus` of
     * `Challenge`. No ConnexPay response has ever contained any of those names. There is no
     * `threeDSecure` block at all: a pending authentication comes back as HTTP 202 with a `status`
     * of `3DS - Pending Fingerprint` or `3DS - Pending User Challenge`, a `redirectUrl`, and — on
     * the fingerprint step only — a `redirectUrlRequestPayload`.
     *
     * The consequence was not a dormant branch. A 202 carries no `wasProcessed`, so
     * {@see isSuccessful()} answered false, and with no challenge found either, every ConnexPay
     * payment that needed 3DS was recorded as an acquirer decline.
     *
     * `payload` is the form body verbatim — `threeDSMethodData=<base64>`, not the base64 alone —
     * because that is what the browser posts. Absent on the challenge step, which is why a
     * challenge carries a url and only sometimes something to send with it.
     *
     * `guid` is the identity, and for this gateway it is the right one. ConnexPay does not publish
     * a `threeDSServerTransID` field: it is buried inside the base64 payload, exists only on the
     * fingerprint step, and is not what resumes anything — the merchant completes the step and
     * calls the same endpoint again against this transaction. The value is stable across both
     * steps.
     */
    #[Override]
    public function getChallenge(): ?Challenge
    {
        $status = $this->data['status'] ?? null;

        if (! is_string($status) || ! str_starts_with($status, '3DS - Pending')) {
            return null;
        }

        $url = $this->data['redirectUrl'] ?? null;
        $guid = $this->data['guid'] ?? null;

        // Both or nothing. A step with nowhere to send the cardholder cannot be presented, and one
        // that cannot be named cannot be resumed; reporting no challenge lets the caller treat the
        // payment as unresolved rather than holding it against something unusable.
        if (! is_string($url) || $url === '' || ! is_string($guid) || $guid === '') {
            return null;
        }

        $payload = $this->data['redirectUrlRequestPayload'] ?? null;

        return new ThreeDSChallenge(
            authenticationId: $guid,
            url: $url,
            payload: is_string($payload) && $payload !== '' ? $payload : null,
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
