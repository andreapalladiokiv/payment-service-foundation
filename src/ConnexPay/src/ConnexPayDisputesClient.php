<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Override;
use RuntimeException;

/**
 * HTTP client for ConnexPay's CMS API — the chargeback case endpoints.
 *
 * Separate from {@see ConnexPayClient} and {@see ConnexPayPurchasesClient} because it differs in
 * everything an HTTP client can differ in: a third host, a third auth scheme and a read-only verb.
 *
 * ## The host, the auth, and the credentials nobody has yet
 *
 * `https://cmsapi.connexpay.com` is the only CMS host ConnexPay documents — there is no sandbox
 * spelling of it in the reference, so this client has no environment switch and no second
 * constant. Auth is HTTP Basic, and the username and password are **not** either of the pairs the
 * other two clients use: the CMS requires "merchant-specific API credentials created separately
 * from your normal ConnexPay CRM user". They are constructed in, never read from the environment
 * here, because which credential row holds them is the application's wiring decision (A0) — and
 * because the four `CONNEXPAY_SANDBOX_*` variables in this repository's `.env` are the sales-API
 * pair, so reaching for those names would send the CRM user's password to a host that expects
 * something else.
 *
 * ## The timeout is not the payment path's timeout
 *
 * The sales-API clients allow ten seconds because they sit inside a checkout: a hung call there is
 * a customer waiting. This host is polled, and its documented use is a date-windowed historical
 * read — F0's own fixture recipe asks for a year of cases in one call. Ten seconds would abort
 * that mid-body and, worse, look exactly like a stalled call, so the budget here is wider and the
 * *poll* bounds the cycle instead ({@see Dispute\DisputePoller} carries that cap, including across
 * retries, which a per-request timeout cannot do on its own).
 *
 * ## The optional transport
 *
 * A `ClientInterface` may be injected, exactly as {@see \Techork\PaymentService\Neutrino\NeutrinoClient}
 * allows, and for the same reason: this class is the only place the CMS host, the Basic-auth
 * encoding and the query encoding exist, and no test may call ConnexPay for real. Without the
 * seam, the wire shape of the one thing every dispute in this package depends on would never be
 * asserted. The constructor builds its own client when none is given, which is what production
 * does.
 */
final class ConnexPayDisputesClient implements ConnexPayDisputesClientInterface
{
    /** The only CMS host ConnexPay documents. There is no sandbox variant of it to switch to. */
    public const string BASE_URL = 'https://cmsapi.connexpay.com';

    /**
     * Wider than the sales-API clients' ten seconds on purpose: a year-wide case read is a bulk
     * read, and the cycle cap in the poller — not this number — is what keeps a poll bounded.
     */
    private const float HTTP_TIMEOUT = 30.0;

    private const float HTTP_CONNECT_TIMEOUT = 5.0;

    private readonly ClientInterface $http;

    public function __construct(
        private readonly string $username,
        private readonly string $password,
        string $baseUrl = self::BASE_URL,
        ?ClientInterface $http = null,
    ) {
        $this->http = $http ?? new Client([
            'base_uri' => rtrim($baseUrl, '/').'/',
            'headers' => ['Accept' => 'application/json'],
            'timeout' => self::HTTP_TIMEOUT,
            'connect_timeout' => self::HTTP_CONNECT_TIMEOUT,
        ]);
    }

    /**
     * @param array<string, string> $query
     *
     * @return list<array<string, mixed>>
     */
    #[Override]
    public function get(string $path, array $query): array
    {
        // `request()` and not `get()`: `GuzzleHttp\ClientInterface` declares the four transport
        // verbs and no `get()` — that one exists only on the concrete client, through `__call`. The
        // interface is the seam this class is constructed with, so the call has to be one it makes.
        $response = $this->http->request('GET', ltrim($path, '/'), [
            'query' => $query,
            'auth' => [$this->username, $this->password],
        ]);

        $decoded = json_decode($response->getBody()->getContents(), true);

        // A body that is not a JSON array is a refusal and not an empty result: see the interface
        // docblock. The CMS returns an array of case objects — an HTML error page, a truncated
        // body or a `{}` all mean the read did not happen.
        is_array($decoded) || throw new RuntimeException(
            'ConnexPay CMS answered a chargeback read with a body that is not a JSON array, so no '
            .'case can be read out of it. Treat the window as unread rather than as empty.',
        );

        $cases = [];

        foreach ($decoded as $case) {
            is_array($case) || throw new RuntimeException(
                'ConnexPay CMS answered a chargeback read with an array that is not a list of case '
                .'objects, so the payload is not the shape this adapter understands.',
            );

            /** @var array<string, mixed> $case */
            $cases[] = $case;
        }

        return $cases;
    }
}
