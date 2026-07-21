<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Techork\PaymentService\Revolut\RevolutAuthenticator;

/**
 * @param  array{0: string, 1: string}  $keyPair
 * @param  array<Response|Throwable>  $responses
 * @param  array<int, array<string, mixed>>  $history  captured requests, by reference
 */
function makeRevolutAuthenticator(array $keyPair, array $responses, ?array &$history = null): RevolutAuthenticator
{
    $history = [];
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    return new RevolutAuthenticator(
        clientId: 'client-abc',
        privateKey: $keyPair[0],
        issuer: 'example.com',
        http: new Client([
            'base_uri' => 'https://b2b.revolut.com/',
            'handler' => $stack,
        ]),
    );
}

it('exchanges an authorization code for a refresh token (bootstrap)', function () {
    [$privatePem, $publicPem] = makeRevolutKeyPair();

    $authenticator = makeRevolutAuthenticator(
        [$privatePem, $publicPem],
        [new Response(200, [], json_encode([
            'access_token' => 'acc_first',
            'refresh_token' => 'refresh_stored',
            'token_type' => 'bearer',
            'expires_in' => 2400,
        ]))],
        $history,
    );

    $tokens = $authenticator->exchangeAuthorizationCode('auth-code-123');

    expect($tokens['refresh_token'])->toBe('refresh_stored')
        ->and($tokens['access_token'])->toBe('acc_first');

    $request = $history[0]['request'];
    expect((string) $request->getUri())->toBe('https://b2b.revolut.com/api/1.0/auth/token');

    parse_str((string) $request->getBody(), $form);
    expect($form['grant_type'])->toBe('authorization_code')
        ->and($form['code'])->toBe('auth-code-123')
        ->and($form['client_id'])->toBe('client-abc')
        ->and($form['client_assertion_type'])->toBe('urn:ietf:params:oauth:client-assertion-type:jwt-bearer');

    // The assertion is a valid RS256 JWT signed by our key.
    [$header64, $payload64, $signature64] = explode('.', $form['client_assertion']);
    expect(openssl_verify(
        "{$header64}.{$payload64}",
        revolutBase64UrlDecode($signature64),
        $publicPem,
        OPENSSL_ALGO_SHA256,
    ))->toBe(1)
        ->and(json_decode(revolutBase64UrlDecode($payload64), true)['aud'])->toBe('https://revolut.com');
});

it('refreshes an access token from a stored refresh token (runtime)', function () {
    [$privatePem, $publicPem] = makeRevolutKeyPair();

    $authenticator = makeRevolutAuthenticator(
        [$privatePem, $publicPem],
        [new Response(200, [], json_encode(['access_token' => 'acc_live', 'expires_in' => 2400]))],
        $history,
    );

    $tokens = $authenticator->refreshAccessToken('refresh_stored');

    expect($tokens['access_token'])->toBe('acc_live');

    parse_str((string) $history[0]['request']->getBody(), $form);
    expect($form['grant_type'])->toBe('refresh_token')
        ->and($form['refresh_token'])->toBe('refresh_stored');
});

it('fails the bootstrap when the response omits the refresh token', function () {
    [$privatePem, $publicPem] = makeRevolutKeyPair();

    $authenticator = makeRevolutAuthenticator(
        [$privatePem, $publicPem],
        [new Response(200, [], json_encode(['access_token' => 'acc_first']))],
    );

    $authenticator->exchangeAuthorizationCode('auth-code-123');
})->throws(RuntimeException::class, 'did not contain a refresh_token');
