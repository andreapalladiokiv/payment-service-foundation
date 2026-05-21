<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay\Webhook;

use Psr\Http\Message\ServerRequestInterface;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Webhook\Contract\SignatureVerifier as SignatureVerifierContract;

/**
 * ConnexPay webhook authentication. The publicly documented mechanism is
 * **HTTP Basic Auth** ({@see https://docs.connexpay.com/docs/client-vcc-decisioning})
 * — ConnexPay sends our Bridge-configured username/password in the
 * `Authorization: Basic ...` header on each delivery.
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
    public function verify(ServerRequestInterface $request, GatewayCredential $gateway): bool
    {
        $credentials = $gateway->getCredentials();
        $expectedUsername = (string) ($credentials['username'] ?? '');
        $expectedPassword = (string) ($credentials['password'] ?? '');

        if ($expectedUsername === '' || $expectedPassword === '') {
            return false;
        }

        $authHeader = $request->getHeaderLine('Authorization');
        if (! str_starts_with($authHeader, 'Basic ')) {
            return false;
        }

        $decoded = base64_decode(substr($authHeader, 6), true);
        if ($decoded === false || ! str_contains($decoded, ':')) {
            return false;
        }

        [$username, $password] = explode(':', $decoded, 2);

        // Constant-time comparison on both fields to avoid timing leaks
        // on either component.
        return hash_equals($expectedUsername, $username)
            && hash_equals($expectedPassword, $password);
    }
}
