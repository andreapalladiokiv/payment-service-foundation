<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use Techork\PaymentService\ConnexPay\ConnexPayClient;

/**
 * PROBE, not a regression test. Answers one empirical question no document can:
 * does ConnexPay's v1 acquiring request MODEL bind a currency property that the
 * published reference does not mention?
 *
 * Everything we could read says no: the OpenAPI 3.1 source behind the reference
 * (sales-api.json, v1.0, updated 2026-07-16) has no currency property anywhere
 * on /api/v1/sales or /api/v1/authonlys, three generated SDKs agree, two
 * independent third-party clients hard-code USD, and our own captured sandbox
 * responses (tests/Unit/ConnexPay/*ResponseTest.php, 2026-05-06) never echo a
 * currency. But a field that the JSON binder silently DROPS leaves exactly that
 * trace too. Only sending one can tell those apart.
 *
 * HOW IT DISTINGUISHES THE THREE OUTCOMES
 *
 * ASP.NET-style JSON binding ignores unknown properties, so "we sent Currency
 * and got a 200" proves nothing by itself. The discriminator is a TYPE
 * MISMATCH: send the candidate as a JSON object where a string is expected.
 *
 *   - a property the model KNOWS      -> deserialisation error, 400, and
 *                                        modelState names the property
 *   - a property the model DOES NOT   -> ignored regardless of its type, 200
 *
 * That test is account-independent: model binding happens before any
 * merchant-account currency logic, so a USD-only sandbox can still answer it.
 * Two controls keep it honest:
 *
 *   CONTROL+ (case 2) Amount sent as a non-numeric string. Amount certainly
 *             exists, so this MUST be rejected with Amount named. If it is not,
 *             this API does not report binding errors at all and every
 *             "ACCEPTED" below is uninterpretable — the probe says so.
 *   CONTROL- (case 3) a nonsense property, also as an object. This SHOULD be
 *             accepted, establishing that unknown fields are dropped silently.
 *
 * Then, per candidate spelling: wrong type (is it bound?), garbage ISO value
 * (is it validated?), valid non-account value CAD (is it acted on?), plus a
 * normalised diff of the response against the baseline (is anything honoured or
 * echoed back?).
 *
 * WHAT THIS CAN PROVE
 *   (c) REJECTED with a currency property named in modelState -> the field
 *       exists on the wire contract. The documentation is wrong. Conclusive.
 *   (a) HONOURED: a currency appears in the response, or the CAD response
 *       differs structurally from the baseline. Conclusive.
 *   (b) ACCEPTED but no rejection, no echo, no diff -> no evidence the field
 *       exists; consistent with the binder dropping it.
 *
 * WHAT THIS CANNOT PROVE
 *   ConnexPay documents the sandbox as USD-only ("they will all show as USD"),
 *   so outcome (b) is NOT proof that a CAD/GBP/EUR-provisioned merchant account
 *   behaves the same way. It shows the field is not bound or validated FOR THIS
 *   SANDBOX ACCOUNT. If ConnexPay branches on merchant configuration after
 *   binding, a multi-currency account could still behave differently, and no
 *   request from this account can reveal that. Nor can the sandbox ever show a
 *   currency being APPLIED (everything settles as USD). Outcome (b) therefore
 *   ends in a question for ConnexPay, not a conclusion about production.
 *
 * SAFETY. Sandbox only (the client is pinned to environment 'sandbox'). Every
 * probe places the documented minimum $0.50 hold via /api/v1/authonlys — an
 * auth, never a sale — and voids it immediately; nothing is captured, settled
 * or refunded. Each request carries its own OrderNumber (CCYPROBE-<n>-<ts>) so
 * anything left behind is findable in the CXP portal, and the run prints a
 * ledger of every guid it created with its void result.
 *
 * Run:
 *   CONNEXPAY_SANDBOX_USERNAME=... CONNEXPAY_SANDBOX_PASSWORD=... \
 *   CONNEXPAY_SANDBOX_DEVICE_GUID=... \
 *   vendor/bin/pest src/ConnexPay/tests/Integration/ConnexPayCurrencyFieldProbeTest.php
 */
const CONNEXPAY_PROBE_SKIP = 'Set CONNEXPAY_SANDBOX_USERNAME / _PASSWORD / _DEVICE_GUID to run the ConnexPay currency-field probe.';

/** Documented minimum acquiring amount. Held, then voided. */
const CONNEXPAY_PROBE_AMOUNT = 0.50;

function connexpayProbeConfigured(): bool
{
    return (getenv('CONNEXPAY_SANDBOX_USERNAME') ?: '') !== ''
        && (getenv('CONNEXPAY_SANDBOX_PASSWORD') ?: '') !== ''
        && (getenv('CONNEXPAY_SANDBOX_DEVICE_GUID') ?: '') !== '';
}

function connexpayProbeClient(): ConnexPayClient
{
    static $client = null;

    return $client ??= new ConnexPayClient(
        username: (string) getenv('CONNEXPAY_SANDBOX_USERNAME'),
        password: (string) getenv('CONNEXPAY_SANDBOX_PASSWORD'),
        environment: 'sandbox',
    );
}

/**
 * Minimal AuthOnly body mirroring exactly what AuthorizeRequest::getData()
 * builds for the passing sandbox suite, so a probe differs from a known-good
 * request only by the field under test. RiskData is included because every
 * known-good call in ConnexPaySandboxTest sends it (and sales-api.json marks it
 * required) — a baseline that fails for an unrelated reason would make the
 * whole table unreadable.
 *
 * @return array<string, mixed>
 */
function connexpayProbeBody(string $orderNumber): array
{
    return [
        'DeviceGuid' => (string) getenv('CONNEXPAY_SANDBOX_DEVICE_GUID'),
        'Amount' => CONNEXPAY_PROBE_AMOUNT,
        'TenderType' => 'Credit',
        'OrderNumber' => $orderNumber,
        'Card' => [
            'CardHolderName' => 'Probe Tester',
            'CardNumber' => '4111111111111111',
            // YYMM, as ConnexPayRequestParameters::formatExpirationDate builds
            // it. Captured responses echo '2030-12', which is NOT the request
            // format — sending that shape earns a modelState error.
            'ExpirationDate' => '3012',
            'Cvv2' => '999',
        ],
        'RiskData' => [
            'Name' => 'Probe Tester',
            'BillingAddress1' => '1 Test St',
            'BillingCountryCode' => 'US',
            'BillingPostalCode' => '10001',
            'Email' => 'foundation-tests@example.com',
        ],
    ];
}

/**
 * Volatile keys differ between two identical requests and must not count as a
 * behavioural difference when diffing a candidate response against the
 * baseline.
 */
const CONNEXPAY_PROBE_VOLATILE = [
    'guid', 'timeStamp', 'orderNumber', 'authCode', 'refNumber', 'batchGuid',
    'customerReceipt', 'sequenceNumber', 'idSale', 'incomingTransactionCode',
    // Measured: these differ between two byte-identical requests, so they are
    // per-transaction, not a reaction to anything we sent.
    'cardTransactionIdentifier', 'invoiceNumber', 'omniscore', 'transactionId',
];

/**
 * Shape of a response, ignoring volatile values: key => value for scalars,
 * key => nested shape for arrays. Two silently-ignored fields produce identical
 * shapes; a field the server acted on usually does not.
 *
 * @param  array<string, mixed>  $response
 * @return array<string, mixed>
 */
/**
 * The response with volatile / free-text keys removed outright, for substring
 * scanning. Distinct from {@see connexpayProbeShape}, which keeps them as a
 * placeholder so the diff still notices a key appearing or vanishing.
 *
 * @param  array<string, mixed>  $response
 * @return array<string, mixed>
 */
function connexpayProbeWithoutVolatile(array $response): array
{
    $clean = [];

    foreach ($response as $key => $value) {
        if (in_array((string) $key, CONNEXPAY_PROBE_VOLATILE, true)) {
            continue;
        }

        $clean[$key] = is_array($value) ? connexpayProbeWithoutVolatile($value) : $value;
    }

    return $clean;
}

/**
 * @param  array<string, mixed>  $response
 * @return array<string, mixed>
 */
function connexpayProbeShape(array $response): array
{
    $shape = [];

    foreach ($response as $key => $value) {
        if (in_array((string) $key, CONNEXPAY_PROBE_VOLATILE, true)) {
            $shape[$key] = '(volatile)';

            continue;
        }

        $shape[$key] = is_array($value) ? connexpayProbeShape($value) : $value;
    }

    ksort($shape);

    return $shape;
}

/**
 * @param  array<string, mixed>  $extra   merged into the top-level body
 * @param  array<string, mixed>  $nested  merged into ConnexPayTransaction
 * @return array{outcome: string, detail: string, guid: ?string, blamed: list<string>, shape: array<string, mixed>, echoed: list<string>}
 */
function connexpayProbeSend(int $case, array $extra = [], array $nested = []): array
{
    $body = [...connexpayProbeBody(sprintf('CCYPROBE-%02d-%d', $case, time())), ...$extra];

    if ($nested !== []) {
        $body['ConnexPayTransaction'] = $nested;
    }

    try {
        $response = connexpayProbeClient()->post('/api/v1/authonlys', $body);
    } catch (ClientException|ServerException $e) {
        // The validation message IS the evidence. ConnexPayClient lets
        // GuzzleException through, so read the raw body here: ConnexPay returns
        // ASP.NET `modelState`, which names the exact property it objected to.
        // That naming — not the bare fact of a 400 — is the finding.
        $status = $e->getResponse()->getStatusCode();
        $raw = (string) $e->getResponse()->getBody();
        $decoded = json_decode($raw, true);
        $modelState = is_array($decoded) && is_array($decoded['modelState'] ?? null) ? $decoded['modelState'] : [];

        return [
            'outcome' => 'REJECTED',
            'detail' => "HTTP {$status}: ".preg_replace('/\s+/', ' ', mb_substr($raw, 0, 400)),
            'guid' => null,
            'blamed' => array_map('strval', array_keys($modelState)),
            'shape' => [],
            'echoed' => [],
        ];
    } catch (Throwable $e) {
        return [
            'outcome' => 'ERROR',
            'detail' => $e::class.': '.$e->getMessage(),
            'guid' => null, 'blamed' => [], 'shape' => [], 'echoed' => [],
        ];
    }

    $echoed = [];
    foreach ($response as $key => $value) {
        if (stripos((string) $key, 'currenc') !== false) {
            $echoed[] = $key.'='.json_encode($value);
        }
    }
    // A currency could also come back nested (card{...}, connexPayTransaction{...}).
    // Scan with the free-text fields stripped: customerReceipt is boilerplate
    // that itself contains the word "currency", so scanning the raw response
    // flags every call including the baseline — a false positive that reads as
    // "the field is honoured".
    $flat = json_encode(connexpayProbeWithoutVolatile($response)) ?: '';
    if ($echoed === [] && stripos($flat, 'currenc') !== false) {
        $echoed[] = 'nested: '.mb_substr($flat, max(0, (int) stripos($flat, 'currenc') - 40), 120);
    }

    $processed = ($response['wasProcessed'] ?? null) === true;

    return [
        'outcome' => $processed ? 'ACCEPTED' : 'ACCEPTED-NOT-PROCESSED',
        'detail' => (string) ($response['status'] ?? '(no status)')
            .' | '.(string) ($response['processorResponseMessage'] ?? '(no processor message)')
            .' | amount='.json_encode($response['amount'] ?? null)
            .($echoed === [] ? ' | no currency echoed' : ' | ECHOED '.implode(',', $echoed)),
        'guid' => isset($response['guid']) ? (string) $response['guid'] : null,
        'blamed' => [],
        'shape' => connexpayProbeShape($response),
        'echoed' => $echoed,
    ];
}

/**
 * Dotted paths whose value differs between two shapes, so a divergence can be
 * read rather than guessed at.
 *
 * @param  array<string, mixed>  $a
 * @param  array<string, mixed>  $b
 * @return list<string>
 */
function connexpayProbeDriftKeys(array $a, array $b, string $prefix = ''): array
{
    $keys = [];

    foreach (array_unique([...array_keys($a), ...array_keys($b)]) as $key) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
        $left = $a[$key] ?? null;
        $right = $b[$key] ?? null;

        if (is_array($left) && is_array($right)) {
            $keys = [...$keys, ...connexpayProbeDriftKeys($left, $right, $path)];

            continue;
        }

        if ($left !== $right) {
            $keys[] = $path;
        }
    }

    return $keys;
}

/** Did modelState name a currency-ish property, as opposed to something unrelated? */
function connexpayProbeBlamedCurrency(array $result): bool
{
    return array_any($result['blamed'], fn (string $f) => stripos($f, 'currenc') !== false);
}

/** Release the hold. Returns a one-line audit string for the ledger. */
function connexpayProbeVoid(?string $guid): string
{
    if ($guid === null || $guid === '') {
        return 'nothing to void';
    }

    try {
        $response = connexpayProbeClient()->post('/api/v1/void', [
            'DeviceGuid' => (string) getenv('CONNEXPAY_SANDBOX_DEVICE_GUID'),
            'AuthOnlyGuid' => $guid,
        ]);

        return 'voided: '.(string) ($response['status'] ?? '(no status)');
    } catch (Throwable $e) {
        // Sandbox auths are processed asynchronously; an immediate void can lose
        // the race. One retry, then report loudly so the hold can be voided by
        // hand in the CXP portal (search the CCYPROBE-* OrderNumber).
        sleep(8);

        try {
            $response = connexpayProbeClient()->post('/api/v1/void', [
                'DeviceGuid' => (string) getenv('CONNEXPAY_SANDBOX_DEVICE_GUID'),
                'AuthOnlyGuid' => $guid,
            ]);

            return 'voided on retry: '.(string) ($response['status'] ?? '(no status)');
        } catch (Throwable $retry) {
            return '!! NOT VOIDED — void by hand in the CXP portal: '.$retry->getMessage();
        }
    }
}

it('probes whether the v1 acquiring request model binds a currency field', function () {
    /** @var array<int, array{name: string, extra: array<string, mixed>, nested: array<string, mixed>}> $cases */
    $cases = [
        1 => ['name' => 'baseline (nothing added)', 'extra' => [], 'nested' => []],

        // CONTROL+ : a field that certainly exists, sent with the wrong type.
        // Must be REJECTED naming Amount, else binding errors are invisible
        // here and no ACCEPTED result below means anything.
        2 => ['name' => 'CONTROL+ Amount = "not-a-number"', 'extra' => ['Amount' => 'not-a-number'], 'nested' => []],

        // CONTROL- : a field that certainly does not exist, same wrong type as
        // the candidates. Should be ACCEPTED, proving unknown fields are dropped.
        3 => ['name' => 'CONTROL- ZzNoSuchField = {}', 'extra' => ['ZzNoSuchField' => ['x' => 1]], 'nested' => []],

        // Candidate: Currency. Type mismatch first — that is the real test.
        4 => ['name' => 'Currency = {} (type mismatch)', 'extra' => ['Currency' => ['x' => 1]], 'nested' => []],
        5 => ['name' => 'Currency = "ZZZ" (garbage ISO)', 'extra' => ['Currency' => 'ZZZ'], 'nested' => []],
        6 => ['name' => 'Currency = "CAD" (valid, non-account)', 'extra' => ['Currency' => 'CAD'], 'nested' => []],

        // Candidate: CurrencyCode — the spelling used on the issuing endpoints
        // and, notably, on the Sales File Upload CSV (field 3, mandatory).
        7 => ['name' => 'CurrencyCode = {} (type mismatch)', 'extra' => ['CurrencyCode' => ['x' => 1]], 'nested' => []],
        8 => ['name' => 'CurrencyCode = "ZZZ"', 'extra' => ['CurrencyCode' => 'ZZZ'], 'nested' => []],
        9 => ['name' => 'CurrencyCode = "CAD"', 'extra' => ['CurrencyCode' => 'CAD'], 'nested' => []],

        // Remaining spellings, type-mismatch form only (cheapest decisive form).
        10 => ['name' => 'currencyCode = {} (camelCase)', 'extra' => ['currencyCode' => ['x' => 1]], 'nested' => []],
        11 => ['name' => 'TransactionCurrency = {}', 'extra' => ['TransactionCurrency' => ['x' => 1]], 'nested' => []],
        12 => ['name' => 'ISOCurrencyCode = {}', 'extra' => ['ISOCurrencyCode' => ['x' => 1]], 'nested' => []],
        13 => ['name' => 'AccountingCurrency = {}', 'extra' => ['AccountingCurrency' => ['x' => 1]], 'nested' => []],

        // The v2 checkout-session carries Currency inside its Sale object, so
        // try the nested shape on v1 too.
        14 => ['name' => 'ConnexPayTransaction.Currency = {}', 'extra' => [], 'nested' => ['Currency' => ['x' => 1]]],
        15 => ['name' => 'ConnexPayTransaction.Currency = "CAD"', 'extra' => [], 'nested' => ['Currency' => 'CAD']],

        // Baseline again: proves nothing drifted (token expiry, rate limiting,
        // sandbox flakiness) between the first case and the last.
        16 => ['name' => 'baseline repeated', 'extra' => [], 'nested' => []],
    ];

    $report = [];
    $ledger = [];

    foreach ($cases as $number => $case) {
        $result = connexpayProbeSend($number, $case['extra'], $case['nested']);
        $result['name'] = $case['name'];

        if ($result['guid'] !== null) {
            $ledger[] = sprintf('case %02d %s -> %s', $number, $result['guid'], connexpayProbeVoid($result['guid']));
        }

        $report[$number] = $result;
    }

    $line = str_repeat('-', 118)."\n";
    fwrite(STDERR, "\n=== ConnexPay v1 /authonlys currency-field probe (sandbox) ===\n".$line);
    foreach ($report as $number => $result) {
        fwrite(STDERR, sprintf(
            "%2d %-38s %-22s %s%s\n",
            $number,
            $result['name'],
            $result['outcome'],
            $result['detail'],
            $result['blamed'] === [] ? '' : ' [modelState blamed: '.implode(', ', $result['blamed']).']',
        ));
    }
    fwrite(STDERR, $line."HOLDS CREATED AND RELEASED:\n");
    fwrite(STDERR, $ledger === [] ? "  (none)\n" : '  '.implode("\n  ", $ledger)."\n");

    $baseline = $report[1];
    $baselineShape = $baseline['shape'];
    $positiveControl = $report[2];
    $negativeControl = $report[3];

    // Structural drift between a candidate's response and the baseline's is the
    // "honoured" signal that does not depend on the server echoing a currency.
    // It is only usable if drift does NOT also appear between two responses that
    // sent no currency at all — so the controls are measured the same way, not
    // excluded. Case 16 is a literal repeat of the baseline: if it drifts, the
    // signal is noise (risk scoring and per-call records vary run to run).
    $diverged = [];
    $divergedControls = [];
    $driftKeys = [];
    foreach ($report as $number => $result) {
        if ($number === 1 || $number === 2 || $result['shape'] === []) {
            continue;
        }

        if ($result['shape'] === $baselineShape) {
            continue;
        }

        $keys = connexpayProbeDriftKeys($baselineShape, $result['shape']);
        $driftKeys[$number] = $keys;

        if (str_contains(strtolower($result['name']), 'currenc')) {
            $diverged[] = $number;
        } else {
            $divergedControls[] = $number;
        }
    }

    if ($driftKeys !== []) {
        fwrite(STDERR, $line."RESPONSE DRIFT vs baseline (keys whose value differs):\n");
        foreach ($driftKeys as $number => $keys) {
            fwrite(STDERR, sprintf("  case %02d %-38s %s\n", $number, $report[$number]['name'], implode(', ', $keys) ?: '(none)'));
        }
    }

    $blamedCurrency = array_values(array_filter(
        array_keys($report),
        fn (int $n) => connexpayProbeBlamedCurrency($report[$n]),
    ));
    // An echo only counts if the BASELINE does not produce it too. The baseline
    // sends no currency field at all, so anything it also "echoes" is noise
    // from the response itself, not the server retaining what we sent.
    $echoedCurrency = array_values(array_filter(
        array_keys($report),
        fn (int $n) => $report[$n]['echoed'] !== [] && $baseline['echoed'] === [] && $n !== 1,
    ));

    fwrite(STDERR, $line."VERDICT: ");

    if ($baseline['outcome'] === 'REJECTED' || $baseline['outcome'] === 'ERROR' || $report[16]['outcome'] !== $baseline['outcome']) {
        fwrite(STDERR, "INCONCLUSIVE — the baseline itself failed or drifted mid-run ({$baseline['detail']}). "
            ."Fix the baseline before reading anything above.\n");
    } elseif ($echoedCurrency !== []) {
        fwrite(STDERR, '(a) HONOURED — a currency came back in the response for case(s) '
            .implode(', ', $echoedCurrency).". The field is real and retained: THE DOCS ARE WRONG.\n");
    } elseif ($blamedCurrency !== []) {
        fwrite(STDERR, '(c) REJECTED BY NAME — modelState named a currency property for case(s) '
            .implode(', ', $blamedCurrency).". The model binds it: THE DOCS ARE WRONG.\n");
    } elseif ($diverged !== [] && $divergedControls !== []) {
        fwrite(STDERR, 'SHAPE DIFF UNUSABLE — case(s) '.implode(', ', $diverged).' drifted, but so did control case(s) '
            .implode(', ', $divergedControls)." which sent no currency at all. The drift is per-transaction noise, "
            ."not a reaction to the field; judge by the binding evidence above instead.\n");
    } elseif ($diverged !== []) {
        fwrite(STDERR, '(a?) DIVERGED — case(s) '.implode(', ', $diverged)
            ." produced a different response shape than the baseline, while the controls did not. "
            ."Something was acted on; read the drift keys.\n");
    } elseif ($positiveControl['outcome'] !== 'REJECTED' || ! array_any($positiveControl['blamed'], fn (string $f) => stripos($f, 'amount') !== false)) {
        fwrite(STDERR, "INCONCLUSIVE — CONTROL+ (case 2) did not produce a modelState error naming Amount, so this API "
            ."does not report binding errors in a way this probe can read. Every ACCEPTED row above is therefore "
            ."uninterpretable, NOT evidence of absence.\n");
    } elseif (str_starts_with($negativeControl['outcome'], 'ACCEPTED')) {
        fwrite(STDERR, "(b) ACCEPTED BUT IGNORED — binding errors ARE visible (case 2 blamed Amount) yet no currency "
            ."spelling was ever blamed, echoed, or changed the response, and a nonsense field was accepted just the same "
            ."(case 3). No currency field is bound on /api/v1/authonlys for this account: the documentation is right.\n");
    } else {
        fwrite(STDERR, "MIXED — controls disagree with each other. Read the table rather than this line.\n");
    }

    fwrite(STDERR,
        "SCOPE: sandbox is USD-only, so outcome (b) shows only that the field is not bound/validated FOR THIS\n"
        ."       ACCOUNT. It cannot show what a CAD/GBP/EUR-provisioned merchant account would do, and the sandbox\n"
        ."       can never show a currency being applied. On (b), the open question for ConnexPay is: for non-USD\n"
        ."       acquiring, is a separate DeviceGuid / merchant account issued per currency?\n".$line."\n");

    // The only assertion is that the baseline worked; everything else is a
    // finding about the vendor, not a property of our code, and must not turn a
    // CI run red.
    expect($baseline['outcome'])->toBe('ACCEPTED', 'baseline AuthOnly failed, probe not interpretable: '.$baseline['detail']);
})->skip(! connexpayProbeConfigured(), CONNEXPAY_PROBE_SKIP);
