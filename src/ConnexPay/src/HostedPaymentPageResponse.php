<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use Override;
use Techork\PaymentService\Common\Contract\Challenge;
use Techork\PaymentService\Common\ValueObject\Challenge\RedirectChallenge;

/**
 * Response to `POST /api/v1/HostedPaymentPageRequests`.
 *
 * A hosted page is never a completed payment: the endpoint hands back a
 * short-lived token, the buyer pays on ConnexPay's own page, and the sale is
 * announced later by the `sale.card.auth.*` webhook. So this reports a redirect
 * challenge and no success — {@see \Techork\PaymentService\Gateway\PaymentGatewayRouter}
 * inspects the challenge before `isSuccessful()`, and the intent parks in
 * `RequiresAction` until the webhook lands.
 *
 * The reference is our own `OrderNumber` (the payment intent id), not a sale
 * guid: verified against the sandbox, the response carries no guid because the
 * sale does not exist until someone pays, and there is no endpoint to read the
 * request back afterwards. The webhook handler correlates on `orderNumber` for
 * exactly that reason.
 */
final class HostedPaymentPageResponse extends ConnexPayResponse
{
    private const string PAGE_PATH = '/HostedPaymentPage/';

    /**
     * Deliberately never true — the money has not moved yet. Stated outright
     * rather than leaning on the base class happening to read an absent
     * `wasProcessed` as false.
     */
    #[Override]
    public function isSuccessful(): bool
    {
        return false;
    }

    #[Override]
    public function getTransactionReference(): ?string
    {
        $orderNumber = $this->data['orderNumber'] ?? null;

        return is_string($orderNumber) && $orderNumber !== '' ? $orderNumber : null;
    }

    #[Override]
    public function getChallenge(): ?Challenge
    {
        $token = $this->tempToken();
        $reference = $this->getTransactionReference();
        $host = $this->pageHost();

        if ($token === null || $reference === null || $host === null) {
            return null;
        }

        return new RedirectChallenge(
            transactionId: $reference,
            url: $host.self::PAGE_PATH.$token,
            formFields: [],
        );
    }

    #[Override]
    public function getMessage(): ?string
    {
        if (isset($this->data['error'])) {
            return (string) $this->data['error'];
        }

        // A token with nowhere to send the buyer is worse than a plain failure,
        // because the challenge silently goes missing and the router falls back
        // to reporting an unsuccessful response. Name it.
        if ($this->tempToken() !== null && $this->pageHost() === null) {
            return 'ConnexPay returned a hosted-page token but no otherUrl to derive the page host from.';
        }

        if ($this->tempToken() !== null && $this->getTransactionReference() === null) {
            return 'ConnexPay accepted the hosted-page request without an OrderNumber, leaving the sale webhook nothing to correlate on.';
        }

        return $this->data['message'] ?? parent::getMessage();
    }

    private function tempToken(): ?string
    {
        $token = $this->data['tempToken'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * Scheme + host of the hosted page, read back from `otherUrl` — ConnexPay
     * names its own result page there (`…/HostedPaymentResult`), served from the
     * same host as the payment page. Reading it beats hardcoding: only the
     * sandbox host is documented anywhere, so the production one would be a
     * guess, and a wrong guess sends buyers into the void.
     */
    private function pageHost(): ?string
    {
        $other = $this->data['otherUrl'] ?? null;

        if (! is_string($other) || $other === '') {
            return null;
        }

        $parts = parse_url($other);
        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;

        return $scheme === null || $host === null ? null : $scheme.'://'.$host;
    }
}
