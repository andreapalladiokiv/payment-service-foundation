<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Techork\PaymentService\Revolut\RevolutClient;

/**
 * Covers the runtime OAuth flow the client performs on its own: exchanging the
 * stored refresh token for an access token at POST /api/1.0/auth/token (with a
 * signed JWT client assertion) before the first API call, then caching it.
 */

/**
 * @param  array{0: string, 1: string}  $keyPair
 * @param  array<Response|Throwable>  $responses
 * @param  array<int, array<string, mixed>>  $history  captured requests, by reference
 */
function makeRevolutAuthClient(array $keyPair, array $responses, ?array &$history = null): RevolutClient
{
    $history = [];
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    return new RevolutClient(
        clientId: 'client-abc',
        privateKey: $keyPair[0],
        refreshToken: 'refresh-xyz',
        issuer: 'example.com',
        baseUrl: RevolutClient::PRODUCTION_BASE_URL,
        http: new Client([
            'base_uri' => RevolutClient::PRODUCTION_BASE_URL.'/',
            'handler' => $stack,
        ]),
    );
}

it('exchanges the refresh token via a signed JWT assertion before the first request', function () {
    [$privatePem, $publicPem] = makeRevolutKeyPair();

    $client = makeRevolutAuthClient(
        [$privatePem, $publicPem],
        [
            new Response(200, [], json_encode(['access_token' => 'acc_live', 'token_type' => 'bearer', 'expires_in' => 2400])),
            new Response(200, [], json_encode(['id' => 'card-1'])),
        ],
        $history,
    );

    $result = $client->post('/api/1.0/cards', ['holder_id' => 'holder-1']);

    expect($result)->toBe(['id' => 'card-1']);

    // First call: the token exchange.
    $tokenRequest = $history[0]['request'];
    expect($tokenRequest->getMethod())->toBe('POST')
        ->and((string) $tokenRequest->getUri())->toBe('https://b2b.revolut.com/api/1.0/auth/token');

    parse_str((string) $tokenRequest->getBody(), $form);
    expect($form['grant_type'])->toBe('refresh_token')
        ->and($form['refresh_token'])->toBe('refresh-xyz')
        ->and($form['client_id'])->toBe('client-abc')
        ->and($form['client_assertion_type'])->toBe('urn:ietf:params:oauth:client-assertion-type:jwt-bearer');

    // The client assertion is a valid RS256 JWT signed with our private key.
    [$header64, $payload64, $signature64] = explode('.', $form['client_assertion']);
    $header = json_decode(revolutBase64UrlDecode($header64), true);
    $payload = json_decode(revolutBase64UrlDecode($payload64), true);

    expect($header)->toBe(['alg' => 'RS256', 'typ' => 'JWT'])
        ->and($payload['iss'])->toBe('example.com')
        ->and($payload['sub'])->toBe('client-abc')
        ->and($payload['aud'])->toBe('https://revolut.com')
        ->and($payload['exp'])->toBeGreaterThan($payload['iat']);

    $verified = openssl_verify(
        "{$header64}.{$payload64}",
        revolutBase64UrlDecode($signature64),
        $publicPem,
        OPENSSL_ALGO_SHA256,
    );
    expect($verified)->toBe(1);

    // Second call: the API request carries the returned access token.
    expect($history[1]['request']->getHeaderLine('Authorization'))->toBe('Bearer acc_live');
});

it('caches the access token across requests', function () {
    [$privatePem, $publicPem] = makeRevolutKeyPair();

    $client = makeRevolutAuthClient(
        [$privatePem, $publicPem],
        [
            new Response(200, [], json_encode(['access_token' => 'acc_live', 'expires_in' => 2400])),
            new Response(200, [], json_encode(['id' => 'card-1'])),
            new Response(204, [], ''),
        ],
        $history,
    );

    $client->post('/api/1.0/cards', ['holder_id' => 'holder-1']);
    $client->delete('/api/1.0/cards/card-1');

    // 1 token exchange + 2 API calls — the token is fetched only once.
    expect($history)->toHaveCount(3)
        ->and((string) $history[0]['request']->getUri())->toContain('/auth/token')
        ->and((string) $history[1]['request']->getUri())->not->toContain('/auth/token')
        ->and((string) $history[2]['request']->getUri())->not->toContain('/auth/token');
});

it('fails when the configured private key is not a valid PEM key', function () {
    $client = makeRevolutAuthClient(
        ['not-a-key', ''],
        [new Response(200, [], json_encode(['access_token' => 'acc']))],
    );

    $client->post('/api/1.0/cards');
})->throws(RuntimeException::class, 'not a valid PEM key');

it('fails when the token response has no access token', function () {
    [$privatePem, $publicPem] = makeRevolutKeyPair();

    $client = makeRevolutAuthClient(
        [$privatePem, $publicPem],
        [new Response(200, [], json_encode(['token_type' => 'bearer']))],
    );

    $client->post('/api/1.0/cards');
})->throws(RuntimeException::class, 'did not contain an access_token');
