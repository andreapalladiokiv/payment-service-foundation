<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay\Concern;

use Techork\PaymentService\Common\Contract\Challenge;
use Techork\PaymentService\Common\ValueObject\Challenge\ThreeDSChallenge;
use Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult;
use Techork\PaymentService\ConnexPay\ConnexPaySchemeChecks;
use Techork\PaymentService\Gateway\Contract\AuthorizationResult;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Gateway\Contract\RegistrationResult;

/**
 * Reads a ConnexPay sales-API body and answers with one of our results.
 *
 * This is what a `ConnexPayResponse` and its six empty subclasses used to be. They existed
 * because Omnipay needed a common object to interrogate through `isSuccessful()` /
 * `getTransactionReference()` / `getMessage()`, and then a second layer
 * (a shared `ResultAssembler`) folded those answers into a result — so the same payload was read
 * twice, once behind an interface that could not say what it held and once by a folder that had
 * to ask. Neither indirection has a job left: the operation knows which endpoint it called and
 * maps the answer once, here, where the shape is named.
 *
 * The bodies of every endpoint that goes through the sales API agree on the four fields below —
 * `wasProcessed`, `guid`, `processorResponseMessage`/`status`, and the AVS / CVV letters — which
 * is why one trait serves sale, auth-only, capture, void and return alike. The card-issuing
 * endpoints do not, which is why they map their own answers in their own operations.
 */
trait MapsConnexPayOutcome
{
    /**
     * For operations carrying no extra signals — capture, cancel, refund.
     *
     * @param  array<string, mixed>  $response
     */
    protected function outcome(array $response): GatewayResult
    {
        if (! self::wasProcessed($response)) {
            return GatewayResult::failed(self::responseMessage($response) ?? 'Gateway returned an unsuccessful response.');
        }

        $reference = self::successReference($response);

        if ($reference === null) {
            return GatewayResult::failed(GatewayResult::UNNAMED_SUCCESS);
        }

        return GatewayResult::succeeded($reference)->withMetadata(self::transactionMetadata($response));
    }

    /**
     * For sale / auth-only — folds the 3DS challenge and the AVS / CVC letters onto an
     * {@see AuthorizationResult}.
     *
     * A challenge is checked before success, and deliberately: a payment awaiting a step-up is
     * not yet successful and not a failure either, and ConnexPay answers a pending
     * authentication with HTTP 202 and no `wasProcessed` at all — so reading success first
     * would book every authenticated payment as an acquirer decline, which is exactly what it
     * used to do.
     *
     * @param  array<string, mixed>  $response
     */
    protected function authorization(array $response): AuthorizationResult
    {
        $challenge = self::threeDSChallenge($response);
        $reference = self::successReference($response);

        if ($challenge !== null) {
            if ($reference === null) {
                return AuthorizationResult::failed(GatewayResult::UNNAMED_SUCCESS);
            }

            return self::withCardChecks(
                AuthorizationResult::requiresAction($reference, $challenge),
                $response,
            )->withMetadata(self::openingMetadata($response, $reference));
        }

        if (! self::wasProcessed($response)) {
            return AuthorizationResult::failed(self::responseMessage($response) ?? 'Gateway returned an unsuccessful response.');
        }

        if ($reference === null) {
            return AuthorizationResult::failed(GatewayResult::UNNAMED_SUCCESS);
        }

        return self::withCardChecks(
            AuthorizationResult::succeeded($reference),
            $response,
        )->withMetadata(self::openingMetadata($response, $reference));
    }

    /**
     * For tokenize / registerPaymentMethod — the `/api/v1/verify` answer, already flattened by
     * the operation into `guid` / `customerGuid` plus whichever verification letters that
     * operation forwards.
     *
     * @param  array<string, mixed>  $response
     */
    protected function registration(array $response): RegistrationResult
    {
        if (! self::wasProcessed($response)) {
            return RegistrationResult::failed(self::responseMessage($response) ?? 'Gateway returned an unsuccessful response.');
        }

        $reference = self::successReference($response);

        if ($reference === null) {
            return RegistrationResult::failed(GatewayResult::UNNAMED_SUCCESS);
        }

        $customerReference = $response['customerGuid'] ?? null;
        [$line, $postal, $cvc] = self::cardChecks($response);

        return RegistrationResult::succeeded($reference)
            ->withCustomerReference(is_string($customerReference) && $customerReference !== '' ? $customerReference : null)
            ->withChecks($line, $postal, $cvc);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected static function wasProcessed(array $response): bool
    {
        return ($response['wasProcessed'] ?? false) === true;
    }

    /**
     * The reference a body reporting success must name, or null when it named none.
     *
     * A success that identifies nothing is unreachable afterwards: the reference is the only
     * handle the ports ever get, so nothing could capture, cancel or refund it. Callers turn
     * this into a failure rather than recording a payment no later operation can address.
     *
     * @param  array<string, mixed>  $response
     */
    protected static function successReference(array $response): ?string
    {
        $guid = $response['guid'] ?? null;

        return is_string($guid) && $guid !== '' ? $guid : null;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected static function responseMessage(array $response): ?string
    {
        $message = $response['processorResponseMessage'] ?? $response['status'] ?? null;

        return is_string($message) && $message !== '' ? $message : null;
    }

    /**
     * The incoming transaction code is the merchant-facing id the legacy API
     * contract exposes as `acquirer_id` — it exists only in the sale /
     * capture response body (capture nests it under `sale`, which
     * {@see \Techork\PaymentService\ConnexPay\Capture} already unwraps), so it must be
     * persisted with the reference or it's gone until a backfill.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    protected static function transactionMetadata(array $response): array
    {
        $code = $response['connexPayTransaction']['incomingTransCode']
            ?? $response['ConnexPayTransaction']['IncomingTransCode']
            ?? null;

        return $code === null || $code === ''
            ? []
            : ['incoming_transaction_code' => (string) $code];
    }

    /**
     * The gateway's metadata plus `opening_transaction_reference` — the reference of the
     * transaction that OPENED the payment intent.
     *
     * Recorded because `reference` cannot answer that later: it overwrites on transition, so
     * once a capture lands the row holds the settle reference. Only the opening operations
     * write it, which is why {@see outcome()} does not.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    protected static function openingMetadata(array $response, string $reference): array
    {
        return [...self::transactionMetadata($response), 'opening_transaction_reference' => $reference];
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
     * The consequence was not a dormant branch. A 202 carries no `wasProcessed`, so the payment
     * read as unsuccessful, and with no challenge found either, every ConnexPay payment that
     * needed 3DS was recorded as an acquirer decline.
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
     *
     * @param  array<string, mixed>  $response
     */
    protected static function threeDSChallenge(array $response): ?Challenge
    {
        $status = $response['status'] ?? null;

        if (! is_string($status) || ! str_starts_with($status, '3DS - Pending')) {
            return null;
        }

        $url = $response['redirectUrl'] ?? null;
        $guid = $response['guid'] ?? null;

        // Both or nothing. A step with nowhere to send the cardholder cannot be presented, and one
        // that cannot be named cannot be resumed; reporting no challenge lets the caller treat the
        // payment as unresolved rather than holding it against something unusable.
        if (! is_string($url) || $url === '' || ! is_string($guid) || $guid === '') {
            return null;
        }

        $payload = $response['redirectUrlRequestPayload'] ?? null;

        return new ThreeDSChallenge(
            authenticationId: $guid,
            url: $url,
            payload: is_string($payload) && $payload !== '' ? $payload : null,
        );
    }

    /**
     * ConnexPay reports one AVS letter and one CVV letter at the top level, on both
     * `/api/v1/verify` and the sale endpoints. Null means the body carried no signal for that
     * field, which is not the same as {@see CheckResult::Unchecked} — that is a signal saying
     * the check did not run.
     *
     * @param  array<string, mixed>  $response
     * @return array{0: ?CheckResult, 1: ?CheckResult, 2: ?CheckResult}
     */
    protected static function cardChecks(array $response): array
    {
        $avs = $response['addressVerificationCode'] ?? $response['AddressVerificationCode'] ?? null;
        $cvv = $response['cvvVerificationCode'] ?? $response['CvvVerificationCode'] ?? null;

        [$line, $postal] = $avs === null || $avs === ''
            ? [null, null]
            : ConnexPaySchemeChecks::avsToLineAndPostal((string) $avs);

        return [
            $line,
            $postal,
            $cvv === null || $cvv === '' ? null : ConnexPaySchemeChecks::cvvToCheckResult((string) $cvv),
        ];
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected static function withCardChecks(AuthorizationResult $result, array $response): AuthorizationResult
    {
        [$line, $postal, $cvc] = self::cardChecks($response);

        return $result->withChecks($line, $postal, $cvc);
    }
}
