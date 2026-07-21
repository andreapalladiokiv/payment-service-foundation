#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * One-time bootstrap: exchange a Revolut Business portal authorization code
 * for the long-lived refresh token to store with the gateway credentials.
 *
 * Prerequisites (done once in the Revolut Business portal — see README):
 *   1. Upload the public key of your RSA key pair and set an OAuth redirect
 *      URI. Revolut issues a client id.
 *   2. Enable the API app — the browser is redirected to the redirect URI with
 *      a short-lived `?code=...` (valid ~2 minutes). Grab that code and run
 *      this script immediately.
 *
 * Usage:
 *   php bin/revolut-obtain-refresh-token.php \
 *       --client-id=<client_id> \
 *       --issuer=<oauth-redirect-domain> \
 *       --private-key=/path/to/privatekey.pem \
 *       --code=<authorization_code> \
 *       [--base-url=https://b2b.revolut.com]
 *
 * On success it prints the refresh_token (and the first access_token) — store
 * the refresh_token as the gateway's `refreshToken` credential.
 */

use GuzzleHttp\Client;
use Techork\PaymentService\Revolut\RevolutAuthenticator;
use Techork\PaymentService\Revolut\RevolutClient;

foreach ([
    __DIR__.'/../vendor/autoload.php',        // package installed standalone
    __DIR__.'/../../../vendor/autoload.php',  // inside the monorepo
    __DIR__.'/../../../../autoload.php',       // installed as a dependency
] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;

        break;
    }
}

$options = getopt('', ['client-id:', 'issuer:', 'private-key:', 'code:', 'base-url::']);

$required = ['client-id', 'issuer', 'private-key', 'code'];
$missing = array_values(array_filter($required, static fn (string $key): bool => empty($options[$key])));

if ($missing !== []) {
    fwrite(STDERR, 'Missing required option(s): --'.implode(', --', $missing).PHP_EOL);
    fwrite(STDERR, 'Run with --client-id, --issuer, --private-key, --code (and optionally --base-url).'.PHP_EOL);
    exit(1);
}

$privateKeyPath = (string) $options['private-key'];
$privateKey = @file_get_contents($privateKeyPath);

if ($privateKey === false) {
    fwrite(STDERR, "Cannot read private key file: {$privateKeyPath}".PHP_EOL);
    exit(1);
}

$baseUrl = ! empty($options['base-url']) ? (string) $options['base-url'] : RevolutClient::PRODUCTION_BASE_URL;

$http = new Client([
    'base_uri' => rtrim($baseUrl, '/').'/',
    'headers' => ['Accept' => 'application/json'],
]);

$authenticator = new RevolutAuthenticator(
    clientId: (string) $options['client-id'],
    privateKey: $privateKey,
    issuer: (string) $options['issuer'],
    http: $http,
);

try {
    $tokens = $authenticator->exchangeAuthorizationCode((string) $options['code']);
} catch (Throwable $e) {
    fwrite(STDERR, 'Token exchange failed: '.$e->getMessage().PHP_EOL);
    exit(1);
}

fwrite(STDOUT, PHP_EOL.'Store this as the gateway `refreshToken` credential:'.PHP_EOL);
fwrite(STDOUT, '  refresh_token: '.$tokens['refresh_token'].PHP_EOL.PHP_EOL);
fwrite(STDOUT, 'First access token (expires in '.($tokens['expires_in'] ?? '?').'s — not stored):'.PHP_EOL);
fwrite(STDOUT, '  access_token:  '.$tokens['access_token'].PHP_EOL);

exit(0);
