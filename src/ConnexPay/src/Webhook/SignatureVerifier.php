<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay\Webhook;

use Override;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Webhook\Contract\InboundWebhook;
use Techork\PaymentService\Gateway\Webhook\Contract\SignatureVerifier as SignatureVerifierContract;

/**
 * ConnexPay webhook authentication: **HTTP Basic Auth**, comparing the
 * `Authorization: Basic ...` header against the credential pair.
 *
 * The citation this was written from —
 * {@see https://docs.connexpay.com/docs/client-vcc-decisioning} — is the **VCC
 * decisioning** webhook, which is a different product from the sale/purchase
 * event stream: its body is flat (`cardGuid`/`transactionId`) and it expects an
 * `approved`/`reasonCode` response. The flat body is why the sale-event parser
 * was written flat too; see {@see EventParser} for that repair. The Basic Auth
 * pair is kept because it is what the dashboard's webhook destination is
 * configured with, but it has never been checked against an observed delivery,
 * and ConnexPay's published sale-event material does not state the mechanism —
 * so treat this as unverified rather than as documented.
 *
 * Reads the same `username`/`password` credentials as the Sales API
 * adapter. The merchant configures the same pair in the ConnexPay
 * dashboard's webhook destination, so a single credential record covers
 * both directions. An empty configuration fails closed (rather than
 * letting unauthenticated traffic through). HMAC-style signing isn't
 * publicly documented for the regular sale/purchase webhooks — if/when
 * it surfaces, extend this verifier rather than swap it.
 */
final readonly class SignatureVerifier implements SignatureVerifierContract
{
    #[Override]
    public function verify(InboundWebhook $webhook, GatewayCredential $gateway): bool
    {
        $credentials = $gateway->getCredentials();
        $expectedUsername = $credentials['username'] ?? '';
        $expectedPassword = $credentials['password'] ?? '';

        if ($expectedUsername === '' || $expectedPassword === '') {
            return false;
        }

        $authHeader = $webhook->header('Authorization');
        if (! str_starts_with($authHeader, 'Basic ')) {
            return false;
        }

        $decoded = base64_decode(substr($authHeader, 6), true);
        if ($decoded === false) {
            return false;
        }

        // The split is the check: without a colon there is one part, which is not a
        // credential pair. Replaces a str_contains() that asserted the same thing twice
        // over while leaving the destructuring unproven.
        $parts = explode(':', $decoded, 2);
        if (count($parts) !== 2) {
            return false;
        }

        [$username, $password] = $parts;

        // Constant-time comparison on both fields to avoid timing leaks
        // on either component.
        return hash_equals($expectedUsername, $username)
            && hash_equals($expectedPassword, $password);
    }
}
