<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Techork\PaymentService\Forter\ForterClient;
use Techork\PaymentService\Forter\ForterHttpClientInterface;

/**
 * {@see ForterClient} was entirely unexecuted, and it is the one class in the
 * package that decides what Forter actually receives: the auth header, the API
 * version and the URL the order is filed under. None of that is visible from
 * {@see \Techork\PaymentService\Forter\ForterRequestMapper} (which only shapes
 * the body) or from the provider (which is tested against
 * {@see ForterHttpClientInterface} doubles), so a wrong header here would have
 * surfaced only as Forter rejecting every screening in production.
 *
 * The class takes an optional `ClientInterface`, so no network is involved: a
 * Guzzle `MockHandler` answers the call and the history middleware hands back
 * the PSR-7 request the class built. Asserting on the real request object —
 * rather than on a hand-rolled recorder — is deliberate, because Guzzle's own
 * URI resolution against `base_uri` is part of what decides the final path.
 *
 * Helpers are prefixed `forterTransport…`; Pest helper functions are global
 * across the whole suite, and `fakeForterClient` / `makeForterScreeningRequest`
 * already exist in the package's Pest.php.
 */

/**
 * A transport that answers with `$response` and appends every request it saw to
 * `$sent`. The `base_uri` is a stand-in for `PRODUCTION_BASE_URL` so that path
 * resolution is observable without reaching the real host.
 *
 * @param  list<RequestInterface>  $sent
 */
function forterTransportRecording(array &$sent, Response $response = new Response(200, [], '{}')): ClientInterface
{
    $stack = HandlerStack::create(new MockHandler([$response]));
    $stack->push(Middleware::history($sent));

    return new Client([
        'handler' => $stack,
        'base_uri' => 'https://forter.test/v2/',
    ]);
}

/** The client under test, wired to a recording transport. */
function forterTransportClient(array &$sent, ?string $siteId = null, Response $response = new Response(200, [], '{}')): ForterClient
{
    return new ForterClient('sk_forter_secret', ForterClient::PRODUCTION_BASE_URL, $siteId, forterTransportRecording($sent, $response));
}

/** The Guzzle client the constructor builds when no transport is injected. */
function forterTransportInlineClient(ForterClient $client): ClientInterface
{
    $property = new ReflectionProperty(ForterClient::class, 'http');

    /** @var ClientInterface */
    return $property->getValue($client);
}

it('files the order under POST /orders/{orderId} on the configured base url', function () {
    // The version segment lives in the base url rather than in the path, so a
    // relative 'orders/…' must resolve *under* it. Guzzle drops everything after
    // the last slash of base_uri when resolving, which is why the trailing slash
    // the constructor appends matters — pinned separately below.
    $sent = [];
    forterTransportClient($sent)->postOrder('order-77', ['x' => 1]);

    expect($sent)->toHaveCount(1)
        ->and($sent[0]['request']->getMethod())->toBe('POST')
        ->and((string) $sent[0]['request']->getUri())->toBe('https://forter.test/v2/orders/order-77');
});

it('authenticates with the secret key as basic username and an empty password', function () {
    // Forter's convention, and the one thing about it that is easy to get wrong:
    // the password half is empty, so the encoded pair ends in a colon. Sending
    // `base64(secret)` without it authenticates as nobody and every screening
    // comes back unauthorized.
    $sent = [];
    forterTransportClient($sent)->postOrder('order-77', []);

    expect($sent[0]['request']->getHeaderLine('Authorization'))
        ->toBe('Basic '.base64_encode('sk_forter_secret:'));
});

it('declares the API version Forter negotiates the response shape by', function () {
    // Asserted as a literal, not against the constant: Forter changes the
    // response shape between versions, so bumping API_VERSION has to be a
    // deliberate act that also revisits FraudDecision parsing.
    $sent = [];
    forterTransportClient($sent)->postOrder('order-77', []);

    expect($sent[0]['request']->getHeaderLine('api-version'))->toBe('2.2')
        ->and(ForterClient::API_VERSION)->toBe('2.2');
});

it('omits the site id header entirely when no site is configured', function () {
    // Forter treats an empty x-forter-siteid as a site named '' rather than as
    // "the account default", so the header must be absent, not blank.
    $sent = [];
    forterTransportClient($sent, siteId: null)->postOrder('order-77', []);

    expect($sent[0]['request']->hasHeader('x-forter-siteid'))->toBeFalse();
});

it('sends the site id header when the account is scoped to one site', function () {
    $sent = [];
    forterTransportClient($sent, siteId: 'site-42')->postOrder('order-77', []);

    expect($sent[0]['request']->getHeaderLine('x-forter-siteid'))->toBe('site-42');
});

it('serialises the mapped body as the JSON request payload', function () {
    // The provider hands the mapper's array straight through; if it were sent as
    // form fields Forter would answer 400 on every order.
    $sent = [];
    forterTransportClient($sent)->postOrder('order-77', ['orderId' => 'order-77', 'totalAmount' => ['amountUSD' => '123.45']]);

    expect((string) $sent[0]['request']->getBody())
        ->toBe('{"orderId":"order-77","totalAmount":{"amountUSD":"123.45"}}')
        ->and($sent[0]['request']->getHeaderLine('Content-Type'))->toBe('application/json');
});

it('escapes the order id so it cannot climb out of the orders path', function () {
    // Order ids reach here from the caller's reference. `rawurlencode` encodes a
    // slash as %2F, keeping a hostile or merely odd reference inside the single
    // path segment Forter expects instead of retargeting the endpoint.
    $sent = [];
    forterTransportClient($sent)->postOrder('../../admin/orders/1', []);

    expect((string) $sent[0]['request']->getUri())
        ->toBe('https://forter.test/v2/orders/..%2F..%2Fadmin%2Forders%2F1');
});

it('decodes a JSON object response into an array', function () {
    $sent = [];
    $decoded = forterTransportClient($sent, response: new Response(200, [], '{"action":"decline","reasonCode":13}'))
        ->postOrder('order-77', []);

    expect($decoded)->toBe(['action' => 'decline', 'reasonCode' => 13]);
});

it('reports no verdict rather than failing when Forter answers with nothing', function (string $body) {
    // Each of these is a body the class explicitly tolerates: an empty
    // response, an unparseable one, and a literal JSON null. All three become
    // an empty array so ForterFraudScreeningProvider takes its
    // no-decision path instead of dying inside the transport.
    $sent = [];

    expect(forterTransportClient($sent, response: new Response(200, [], $body))->postOrder('order-77', []))
        ->toBe([]);
})->with([
    'empty body' => '',
    'not JSON at all' => '<html>502 Bad Gateway</html>',
    'literal JSON null' => 'null',
]);

it('defaults to the production endpoint with the version segment kept resolvable', function () {
    // Reachable only through the inline `new Client`, because an injected
    // transport carries its own base_uri and the constructor's $baseUrl is then
    // never read. Building a Guzzle client performs no I/O, so this stays
    // offline. Without the appended slash Guzzle resolves 'orders/x' as a
    // sibling of '/v2' and posts to /orders/x — a 404 on every screening.
    $client = new ForterClient('sk_forter_secret');

    expect(forterTransportInlineClient($client)->getConfig('base_uri'))
        ->toBeInstanceOf(Psr\Http\Message\UriInterface::class);

    expect((string) forterTransportInlineClient($client)->getConfig('base_uri'))
        ->toBe('https://api.forter.com/v2/')
        ->and(ForterClient::PRODUCTION_BASE_URL)->toBe('https://api.forter.com/v2');
});

it('normalises a proxy base url to exactly one trailing slash', function () {
    // Operators configure the outbound proxy url by hand, so both spellings
    // arrive. A doubled slash would make the resolved path //orders/…, which
    // Forter routes differently from /orders/….
    expect((string) forterTransportInlineClient(new ForterClient('k', 'https://proxy.internal/forter/v2/'))->getConfig('base_uri'))
        ->toBe('https://proxy.internal/forter/v2/')
        ->and((string) forterTransportInlineClient(new ForterClient('k', 'https://proxy.internal/forter/v2'))->getConfig('base_uri'))
        ->toBe('https://proxy.internal/forter/v2/');
});

it('is the transport the provider is written against', function () {
    // The provider only ever holds a ForterHttpClientInterface. Pinned so the
    // concrete client cannot drift out of the contract its only consumer uses.
    expect(new ForterClient('k'))->toBeInstanceOf(ForterHttpClientInterface::class);
});
