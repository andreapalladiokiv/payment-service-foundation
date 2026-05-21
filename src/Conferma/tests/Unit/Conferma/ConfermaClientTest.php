<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Techork\PaymentService\Conferma\ConfermaClient;

/**
 * @return array{0: ConfermaClient, 1: ArrayObject, 2: ArrayObject}
 */
function makeConfermaClient(array $authResponses = [], array $apiResponses = [], array $opts = []): array
{
    $authQueue = $authResponses ?: [
        new Response(200, [], json_encode(['access_token' => 'TKN-1', 'expires' => '2099-01-01T00:00:00Z'])),
    ];
    $apiQueue = $apiResponses ?: [new Response(200, [], json_encode([]))];

    $authContainer = new ArrayObject;
    $apiContainer = new ArrayObject;

    $authStack = HandlerStack::create(new MockHandler($authQueue));
    $authStack->push(GuzzleHttp\Middleware::history($authContainer));

    $apiStack = HandlerStack::create(new MockHandler($apiQueue));
    $apiStack->push(GuzzleHttp\Middleware::history($apiContainer));

    $authHttp = new Client([
        'handler' => $authStack,
        'base_uri' => 'https://assure.cert-confermapay.com/',
    ]);
    $apiHttp = new Client([
        'handler' => $apiStack,
        'base_uri' => 'https://api.cert-confermapay.com/',
        'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
    ]);

    $client = new ConfermaClient(
        clientId: $opts['clientId'] ?? 'cid',
        clientSecret: $opts['clientSecret'] ?? 'csec',
        platformKey: $opts['platformKey'] ?? 'pk-xyz',
        accessToken: $opts['accessToken'] ?? null,
        apiHttp: $apiHttp,
        authHttp: $authHttp,
    );

    return [$client, $authContainer, $apiContainer];
}

it('exchanges client_credentials with Basic header and scope before the first API call', function () {
    [$client, $authHistory, $apiHistory] = makeConfermaClient(
        authResponses: [
            new Response(200, [], json_encode(['access_token' => 'TKN-1', 'expires' => '2099-01-01T00:00:00Z'])),
        ],
        apiResponses: [new Response(200, [], json_encode(['ok' => true]))],
    );

    $client->post('/deployments/v1/deployments', ['foo' => 'bar']);

    expect($authHistory)->toHaveCount(1);
    $authReq = $authHistory[0]['request'];

    expect($authReq->getMethod())->toBe('POST')
        ->and((string) $authReq->getUri())->toBe('https://assure.cert-confermapay.com/token')
        ->and($authReq->getHeaderLine('Authorization'))->toBe('Basic '.base64_encode('cid:csec'))
        ->and($authReq->getHeaderLine('Content-Type'))->toContain('application/x-www-form-urlencoded');

    parse_str((string) $authReq->getBody(), $body);
    expect($body)->toBe([
        'grant_type' => 'client_credentials',
        'scope' => 'pk-xyz',
    ]);
});

it('attaches the bearer to subsequent API requests', function () {
    [$client, , $apiHistory] = makeConfermaClient(
        apiResponses: [new Response(200, [], json_encode(['deploymentId' => 'dep_1']))],
    );

    $result = $client->post('/deployments/v1/deployments', ['hello' => 'world']);

    expect($result)->toBe(['deploymentId' => 'dep_1']);

    $apiReq = $apiHistory[0]['request'];
    expect($apiReq->getMethod())->toBe('POST')
        ->and((string) $apiReq->getUri())->toBe('https://api.cert-confermapay.com/deployments/v1/deployments')
        ->and($apiReq->getHeaderLine('Authorization'))->toBe('Bearer TKN-1')
        ->and(json_decode((string) $apiReq->getBody(), true))->toBe(['hello' => 'world']);
});

it('reuses the bearer across multiple calls until it expires', function () {
    [$client, $authHistory] = makeConfermaClient(
        authResponses: [
            new Response(200, [], json_encode(['access_token' => 'TKN-1', 'expires' => '2099-01-01T00:00:00Z'])),
        ],
        apiResponses: [
            new Response(200, [], json_encode([])),
            new Response(200, [], json_encode([])),
            new Response(200, [], json_encode([])),
        ],
    );

    $client->post('/a');
    $client->put('/b', []);
    $client->get('/c');

    expect($authHistory)->toHaveCount(1);
});

it('skips the auth exchange when an accessToken is provided up-front', function () {
    [$client, $authHistory, $apiHistory] = makeConfermaClient(
        apiResponses: [new Response(200, [], json_encode([]))],
        opts: ['accessToken' => 'preset-token'],
    );

    $client->post('/whatever');

    expect($authHistory)->toHaveCount(0)
        ->and($apiHistory[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer preset-token');
});

it('throws a RuntimeException when auth response lacks access_token', function () {
    [$client] = makeConfermaClient(
        authResponses: [new Response(200, [], json_encode(['oops' => 1]))],
    );

    expect(fn () => $client->post('/x'))
        ->toThrow(RuntimeException::class, 'access_token');
});

it('wraps GuzzleException into a RuntimeException for auth failures', function () {
    [$client] = makeConfermaClient(
        authResponses: [new Response(401, [], 'unauthorized')],
    );

    expect(fn () => $client->post('/x'))
        ->toThrow(RuntimeException::class, 'authentication failed');
});

it('issues PUT to the API host with bearer header', function () {
    [$client, , $apiHistory] = makeConfermaClient(
        apiResponses: [new Response(200, [], json_encode(['updated' => true]))],
    );

    $result = $client->put('/deployments/v1/deployments/dep_42', ['amount' => 100]);

    expect($result)->toBe(['updated' => true]);
    $req = $apiHistory[0]['request'];
    expect($req->getMethod())->toBe('PUT')
        ->and((string) $req->getUri())->toBe('https://api.cert-confermapay.com/deployments/v1/deployments/dep_42')
        ->and($req->getHeaderLine('Authorization'))->toBe('Bearer TKN-1');
});
