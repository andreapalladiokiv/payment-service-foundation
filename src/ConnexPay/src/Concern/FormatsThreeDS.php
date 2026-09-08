<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay\Concern;

use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;
use Techork\PaymentService\Gateway\Exception\IncompleteAuthentication;

/**
 * Maps an authentication result onto ConnexPay's `Card.ThreeDS` block.
 *
 * Separate from {@see BuildsConnexPayPayload} because it belongs to fewer operations than that
 * one does. It used to live in the shared trait and read `getThreeDS()` off Omnipay's parameter
 * bag, so it was inherited by the eleven ConnexPay requests while only five carried an
 * instrument — six of them exposed a `formatThreeDS()` that would have died on
 * `Call to undefined method` the moment anything called it. Nothing did, which is why it went
 * unnoticed; the split made the dependency structural instead of a coincidence, and taking the
 * attestation as an argument now makes it impossible to use where there is none to pass.
 *
 * Use it only on operations that can actually carry an authentication: a capture, void, refund or
 * virtual-card call has no cardholder to authenticate.
 */
trait FormatsThreeDS
{
    /**
     * The `Card.ThreeDS` block, or null when this operation carries no
     * authentication result.
     *
     * ConnexPay accepts this on `/api/v1/verify` as well as on sales and
     * auth-onlys, so a card registration that was authenticated must forward it
     * too — otherwise the authentication is performed and then thrown away, and
     * the issuer sees an unauthenticated verification.
     *
     * The Cavv guard is measured, not defensive. ConnexPay publishes its 3DS
     * field table as an image, so requiredness had to be probed
     * ({@see \ConnexPayThreeDSFieldProbeTest}, sandbox, 2026-08-04). What it
     * found: all five members bind, ECI may be null and the sale still comes
     * back `type: "Secured3D"` — but with a null or absent `Cavv` the response
     * is `type: "Default"`, byte-identical to a request that sent no ThreeDS
     * block at all. So a cryptogram-less attestation is not rejected, it is
     * accepted and processed as UNAUTHENTICATED, and the caller is told nothing.
     * That is the one shape worth refusing: everything downstream, including the
     * stored `challengeResult`, would claim an authentication that the acquirer
     * demonstrably did not apply. ECI is deliberately NOT required here even
     * though Nuvei requires it — per-provider wire truth belongs in the
     * per-provider mapper.
     *
     * @return array<string, mixed>|null
     */
    protected function formatThreeDS(?ThreeDSResult $threeDS): ?array
    {
        if ($threeDS === null) {
            return null;
        }

        if (($threeDS->authenticationValue ?? '') === '') {
            throw IncompleteAuthentication::missingFields('connexpay', $this->threeDSOperation(), ['Cavv']);
        }

        return [
            'Cavv' => $threeDS->authenticationValue,
            'Version' => $threeDS->version?->value,
            'DirectoryServerTransactionID' => $threeDS->dsTransactionId,
            'AcsTransactionId' => $threeDS->acsTransactionId,
            'ECI' => $threeDS->eci?->value,
        ];
    }

    /**
     * The operation named in the refusal, derived from the class rather than restated at each
     * call site — the operation classes are named for the operations, so `CreatePaymentMethod`
     * reports `createPaymentMethod` and there is nothing to keep in step.
     */
    private function threeDSOperation(): string
    {
        return lcfirst(basename(str_replace('\\', '/', static::class)));
    }
}
