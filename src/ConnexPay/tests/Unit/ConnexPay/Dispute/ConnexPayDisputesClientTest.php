<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Techork\PaymentService\ConnexPay\ConnexPayDisputesClient;

/**
 * The one place the CMS host, the Basic-auth encoding and the date query exist.
 *
 * No test in this file touches the network and none ever may: a `MockHandler` answers and Guzzle's
 * history middleware yields the PSR-7 request that was actually built, so the wire shape this whole
 * package depends on is asserted without a call. Guzzle's own resolution of a relative path against
 * `base_uri` is part of what these tests pin, which is why the real request object is inspected
 * rather than a hand-rolled recorder.
 *
 * The credentials used here are invented strings, not the repository's `CONNEXPAY_*` variables:
 * which credential row holds the CMS pair is an open question owned by a human (the CMS API's
 * Basic-auth credentials are created separately from the CRM user and are not the Purchases bearer
 * token), and a test that read them from the environment would quietly assert the wrong pair.
 *
 * The payloads are documentation examples of mine — see
 * `tests/Fixtures/Disputes/get-by-user.doc-sample.json` — not payloads recorded from ConnexPay.
 *
 * Helpers are prefixed `cmsTransport…`; Pest helpers are global for the whole suite.
 */

/** A transport that answers with `$response` and records what it was handed. */
function cmsTransportClient(array &$sent, ?Response $response = null, string $username = 'cms-user', string $password = 'cms-pass'): ConnexPayDisputesClient
{
    $stack = HandlerStack::create(new MockHandler([
        $response ?? new Response(200, [], cmsTransportBody()),
    ]));
    $stack->push(Middleware::history($sent));

    return new ConnexPayDisputesClient($username, $password, ConnexPayDisputesClient::BASE_URL, new Client([
        'handler' => $stack,
        'base_uri' => ConnexPayDisputesClient::BASE_URL.'/',
    ]));
}

function cmsTransportBody(): string
{
    return (string) file_get_contents(__DIR__.'/../../../Fixtures/Disputes/get-by-user.doc-sample.json');
}

/** The decoded query of a sent request, without Guzzle's own encoding in the way. */
function cmsTransportQuery(RequestInterface $request): array
{
    parse_str($request->getUri()->getQuery(), $query);

    return $query;
}

it('reads the documented endpoints over https, from the CMS host, with a date window', function () {
    $sent = [];
    $cases = cmsTransportClient($sent)->get('/api/Chargeback/GetByUser', ['startDate' => '2026-09-01', 'endDate' => '2026-09-14']);

    expect($sent)->toHaveCount(1);

    $request = $sent[0]['request'];

    expect($request->getMethod())->toBe('GET')
        ->and((string) $request->getUri()->getHost())->toBe('cmsapi.connexpay.com')
        ->and($request->getUri()->getScheme())->toBe('https')
        ->and($request->getUri()->getPath())->toBe('/api/Chargeback/GetByUser')
        ->and(cmsTransportQuery($request))->toBe(['startDate' => '2026-09-01', 'endDate' => '2026-09-14']);

    expect($cases)->toHaveCount(4);
});

it('sends the credentials as HTTP Basic, and not as a bearer token or a body field', function () {
    // The CMS pair is created separately from the CRM user, and the Purchases bearer token is a
    // third thing again. Sending either of those here is the failure this test exists to catch.
    $sent = [];
    cmsTransportClient($sent, username: 'cms-user', password: 'cms-pass')->get('/api/Chargeback/GetByResolvedDate', []);

    $request = $sent[0]['request'];

    expect($request->getHeaderLine('Authorization'))->toBe('Basic '.base64_encode('cms-user:cms-pass'))
        ->and($request->getHeaderLine('Authorization'))->not->toContain('Bearer');
});

it('returns the cases as a list of arrays', function () {
    $sent = [];
    $cases = cmsTransportClient($sent)->get('/api/Chargeback/GetByUser', []);

    expect($cases[0]['CaseNumber'])->toBe('CB-1001')
        ->and($cases[2]['CaseType'])->toBe(25)
        ->and($cases[0])->toBeArray();
});

it('refuses a body that is not a JSON array rather than answering with no cases', function (string $body) {
    // An empty list would advance the cursor past a window that was never read, which is the one
    // failure mode this poller cannot notice afterwards: the cases would simply never be seen.
    $sent = [];

    expect(fn () => cmsTransportClient($sent, new Response(200, [], $body))->get('/api/Chargeback/GetByUser', []))
        ->toThrow(RuntimeException::class);
})->with([
    'an HTML error page' => ['<html><body>502</body></html>'],
    'a JSON object' => ['{"error":"unauthorized"}'],
    'a truncated body' => ['[{"CaseNumber":"CB-'],
    'an empty body' => [''],
]);

it('refuses an array that is not a list of case objects', function () {
    $sent = [];

    expect(fn () => cmsTransportClient($sent, new Response(200, [], '["CB-1001","CB-1002"]'))->get('/api/Chargeback/GetByUser', []))
        ->toThrow(RuntimeException::class);
});

it('builds its own transport pointed at the CMS host when none is injected', function () {
    // Production takes this path, and the host is the one thing here that cannot be corrected by
    // configuration: there is no sandbox spelling of it in ConnexPay's reference.
    $client = new ConnexPayDisputesClient('u', 'p');
    $property = new ReflectionProperty(ConnexPayDisputesClient::class, 'http');

    /** @var ClientInterface $http */
    $http = $property->getValue($client);

    expect($http)->toBeInstanceOf(ClientInterface::class)
        ->and(ConnexPayDisputesClient::BASE_URL)->toBe('https://cmsapi.connexpay.com')
        ->and($http)->toBeInstanceOf(Client::class);

    if ($http instanceof Client) {
        // The defaults a production call carries: the host, and the one header this read declares.
        // Neither is asserted through the mock above, because a mock transport has no defaults of
        // its own — which is exactly why the assertion belongs here.
        expect((string) $http->getConfig('base_uri'))->toBe('https://cmsapi.connexpay.com/')
            ->and($http->getConfig('headers')['Accept'])->toBe('application/json');
    }
});
