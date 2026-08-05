<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;
use Techork\PaymentService\Neutrino\NeutrinoClient;
use Techork\PaymentService\Neutrino\NeutrinoHttpClientInterface;

/**
 * {@see NeutrinoClient} was entirely unexecuted. It is the only place the
 * `user-id` / `api-key` pair is attached, and Neutrino answers an unauthenticated
 * call with a 200 carrying an error object — so a credential that never reached
 * the wire would look to
 * {@see \Techork\PaymentService\Neutrino\NeutrinoCardIntelligenceProvider} like a
 * card it simply knows nothing about, and every BIN lookup would quietly return
 * no intelligence.
 *
 * The class takes an optional `ClientInterface`, so a Guzzle `MockHandler`
 * answers and the history middleware yields the PSR-7 request that was built —
 * no network. Guzzle's own resolution of a relative endpoint against `base_uri`
 * is part of what these tests pin, which is why the real request object is
 * inspected rather than a hand-rolled recorder.
 *
 * Helpers are prefixed `neutrinoTransport…`; Pest helpers are global for the
 * whole suite and `fakeNeutrinoClient` already exists in the package's Pest.php.
 */

/**
 * A transport that answers with `$response` and records what it was handed.
 *
 * @param  list<RequestInterface>  $sent
 */
function neutrinoTransportRecording(array &$sent, Response $response = new Response(200, [], '{}')): ClientInterface
{
    $stack = HandlerStack::create(new MockHandler([$response]));
    $stack->push(Middleware::history($sent));

    return new Client([
        'handler' => $stack,
        'base_uri' => 'https://neutrino.test/',
    ]);
}

function neutrinoTransportClient(array &$sent, Response $response = new Response(200, [], '{}')): NeutrinoClient
{
    return new NeutrinoClient('user-9', 'key-9', NeutrinoClient::BASE_URL, neutrinoTransportRecording($sent, $response));
}

/** The Guzzle client the constructor builds when no transport is injected. */
function neutrinoTransportInlineClient(NeutrinoClient $client): ClientInterface
{
    $property = new ReflectionProperty(NeutrinoClient::class, 'http');

    /** @var ClientInterface */
    return $property->getValue($client);
}

/** The sent form body, decoded back into the pairs Neutrino will read. */
function neutrinoTransportForm(RequestInterface $request): array
{
    $pairs = [];
    parse_str((string) $request->getBody(), $pairs);

    return $pairs;
}

it('posts every lookup as form fields, the way the legacy integration did', function () {
    // Neutrino's API is form-encoded, not JSON. Pinned because the params array
    // reaching here is indistinguishable from a JSON body at the call site, and
    // Neutrino answers a JSON post with an error object rather than a 4xx.
    $sent = [];
    neutrinoTransportClient($sent)->request('bin-lookup', ['bin-number' => '411111']);

    expect($sent)->toHaveCount(1)
        ->and($sent[0]['request']->getMethod())->toBe('POST')
        ->and($sent[0]['request']->getHeaderLine('Content-Type'))
        ->toBe('application/x-www-form-urlencoded');
});

it('appends the account credentials to every call', function () {
    // The caller never sees them: providers pass only the lookup params, so this
    // is the single point at which an authenticated call becomes possible.
    $sent = [];
    neutrinoTransportClient($sent)->request('ip-info', ['ip' => '203.0.113.7']);

    expect(neutrinoTransportForm($sent[0]['request']))->toBe([
        'ip' => '203.0.113.7',
        'user-id' => 'user-9',
        'api-key' => 'key-9',
    ]);
});

it('lets the configured credentials win over same-named lookup params', function () {
    // The spread puts the credentials last, so they overwrite rather than being
    // overwritten. That ordering is the security-relevant half: a params array
    // assembled from request input could otherwise re-point the call at another
    // Neutrino account.
    $sent = [];
    neutrinoTransportClient($sent)->request('bin-lookup', [
        'bin-number' => '411111',
        'user-id' => 'attacker',
        'api-key' => 'attacker-key',
    ]);

    expect(neutrinoTransportForm($sent[0]['request']))
        ->toHaveKey('user-id', 'user-9')
        ->toHaveKey('api-key', 'key-9');
});

it('resolves the endpoint under the base url whether or not it is given leading-slashed', function (string $endpoint) {
    // Both spellings occur at the call sites. A leading slash would make Guzzle
    // treat the endpoint as absolute-path and discard any path the base url
    // carries — which is exactly what an outbound proxy configuration adds.
    $sent = [];
    neutrinoTransportClient($sent)->request($endpoint, []);

    expect((string) $sent[0]['request']->getUri())->toBe('https://neutrino.test/bin-lookup');
})->with([
    'bare endpoint' => 'bin-lookup',
    'leading slash' => '/bin-lookup',
]);

it('decodes a JSON object response into an array', function () {
    $sent = [];
    $decoded = neutrinoTransportClient($sent, new Response(200, [], '{"card-brand":"VISA","is-commercial":true}'))
        ->request('bin-lookup', []);

    expect($decoded)->toBe(['card-brand' => 'VISA', 'is-commercial' => true]);
});

it('reports no intelligence rather than failing when Neutrino answers with nothing', function (string $body) {
    // The providers treat an empty array as "no signal" and fall back; the point
    // of these branches is that a truncated or non-JSON answer reaches them as
    // that, instead of throwing out of the transport mid-screening.
    $sent = [];

    expect(neutrinoTransportClient($sent, new Response(200, [], $body))->request('ip-info', []))->toBe([]);
})->with([
    'empty body' => '',
    'not JSON at all' => '<html>504 Gateway Timeout</html>',
    'literal JSON null' => 'null',
]);

it('defaults to the neutrinoapi.net host with a resolvable trailing slash', function () {
    // Reachable only through the inline `new Client`, since an injected transport
    // carries its own base_uri and $baseUrl is then never read. Building a Guzzle
    // client performs no I/O. Note the host: the class docblock names
    // www.neutrinoapi.com while the constant — and therefore the traffic — uses
    // neutrinoapi.net.
    expect(neutrinoTransportInlineClient(new NeutrinoClient('u', 'k'))->getConfig('base_uri'))
        ->toBeInstanceOf(UriInterface::class);

    expect((string) neutrinoTransportInlineClient(new NeutrinoClient('u', 'k'))->getConfig('base_uri'))
        ->toBe('https://neutrinoapi.net/')
        ->and(NeutrinoClient::BASE_URL)->toBe('https://neutrinoapi.net');
});

it('normalises a proxy base url to exactly one trailing slash', function () {
    // Operators write the proxy url both ways; a doubled slash resolves to
    // //bin-lookup, which is a different path.
    expect((string) neutrinoTransportInlineClient(new NeutrinoClient('u', 'k', 'https://proxy.internal/neutrino/'))->getConfig('base_uri'))
        ->toBe('https://proxy.internal/neutrino/')
        ->and((string) neutrinoTransportInlineClient(new NeutrinoClient('u', 'k', 'https://proxy.internal/neutrino'))->getConfig('base_uri'))
        ->toBe('https://proxy.internal/neutrino/');
});

it('is the transport both intelligence providers are written against', function () {
    expect(new NeutrinoClient('u', 'k'))->toBeInstanceOf(NeutrinoHttpClientInterface::class);
});
