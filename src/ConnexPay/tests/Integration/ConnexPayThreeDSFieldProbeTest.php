<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use Techork\PaymentService\ConnexPay\ConnexPayClient;

/**
 * PROBE, not a regression test. Sibling of
 * {@see ConnexPayCurrencyFieldProbeTest} and it borrows that file's method
 * wholesale — type mismatch as the binding discriminator, a positive and a
 * negative control, a repeated baseline, and every hold voided.
 *
 * FOUR QUESTIONS NO CONNEXPAY DOCUMENT ANSWERS
 *
 * Their 3DS field tables are published as IMAGES, and the four 3DS pages are
 * missing from their own doc index (llms.txt), so requiredness cannot be read
 * anywhere. That leaves our own adapter guessing on four points:
 *
 *  Q1 Which of Cavv / Version / DirectoryServerTransactionID / AcsTransactionId
 *     / ECI does the Card.ThreeDS model actually BIND?
 *     {@see \Techork\PaymentService\ConnexPay\Concern\ConnexPayRequestParameters::formatThreeDS}
 *     sends exactly those five names; any it does not bind is silently dropped,
 *     and an authentication we believe we forwarded never reached the issuer.
 *
 *  Q2 Does it REJECT a ThreeDS block whose Cavv or ECI is null? This is not
 *     hypothetical: formatThreeDS() types both as nullable
 *     (`$threeDS->authenticationValue`, `$threeDS->eci?->value`), so any
 *     attestation that did not succeed produces exactly that body today. Nuvei
 *     marks the equivalent fields Required and we now refuse before sending
 *     ({@see \Techork\PaymentService\Gateway\Exception\IncompleteAuthentication});
 *     whether ConnexPay needs the same guard is the reason for this probe.
 *     A silent 200 is the WORSE outcome, not the better one — it means the
 *     liability shift is lost with no error to notice.
 *
 *  Q3 Does Card.IsRecurring bind? Their OpenAPI blob says it exists; a previous
 *     reviewer claimed it does not. Only the wire settles it.
 *
 *  Q4 Which field carries the anchor on a SUBSEQUENT recurring transaction?
 *     Their prose says such a transaction "must reference the Sale Guid returned
 *     from that initial payment" without ever naming the property. Three
 *     plausible spellings are probed; a negative result across all three means
 *     the name has to come from ConnexPay directly.
 *
 * READING THE TABLE. Type mismatch (a JSON object where a scalar belongs) is the
 * discriminator, because ASP.NET-style binding ignores unknown properties — a
 * 200 on its own proves nothing.
 *   REJECTED + the property named in modelState -> the model binds it.
 *   ACCEPTED                                    -> not bound, or bound and not
 *                                                  validated; the controls say which.
 *
 * SAFETY. Sandbox only. Every case places the documented minimum $0.50 hold via
 * /api/v1/authonlys — an auth, never a sale — and voids it immediately. Each
 * request carries its own OrderNumber (TDSPROBE-<n>-<ts>) so anything left
 * behind is findable in the CXP portal, and the run prints a ledger of every
 * guid created with its void result.
 *
 * Run:
 *   CONNEXPAY_SANDBOX_USERNAME=... CONNEXPAY_SANDBOX_PASSWORD=... \
 *   CONNEXPAY_SANDBOX_DEVICE_GUID=... \
 *   vendor/bin/pest src/ConnexPay/tests/Integration/ConnexPayThreeDSFieldProbeTest.php
 */
const CXP_TDS_PROBE_SKIP = 'Set CONNEXPAY_SANDBOX_USERNAME / _PASSWORD / _DEVICE_GUID to run the ConnexPay 3DS-field probe.';

/** Documented minimum acquiring amount. Held, then voided. */
const CXP_TDS_PROBE_AMOUNT = 0.50;

function cxpTdsProbeConfigured(): bool
{
    return (getenv('CONNEXPAY_SANDBOX_USERNAME') ?: '') !== ''
        && (getenv('CONNEXPAY_SANDBOX_PASSWORD') ?: '') !== ''
        && (getenv('CONNEXPAY_SANDBOX_DEVICE_GUID') ?: '') !== '';
}

function cxpTdsProbeClient(): ConnexPayClient
{
    static $client = null;

    return $client ??= new ConnexPayClient(
        username: (string) getenv('CONNEXPAY_SANDBOX_USERNAME'),
        password: (string) getenv('CONNEXPAY_SANDBOX_PASSWORD'),
        environment: 'sandbox',
    );
}

/**
 * A well-formed ThreeDS block using the exact property names and value shapes
 * formatThreeDS() produces, so a probe differs from what our code really sends
 * only by the field under test.
 *
 * @return array<string, mixed>
 */
function cxpTdsProbeThreeDS(): array
{
    return [
        'Cavv' => 'AAABBEg0VhI0VniQEjRWAAAAAAA=',
        'Version' => '2.2.0',
        'DirectoryServerTransactionID' => '9f8e7d6c-5b4a-3210-fedc-ba9876543210',
        'AcsTransactionId' => '01234567-89ab-cdef-0123-456789abcdef',
        'ECI' => '05',
    ];
}

/**
 * @return array<string, mixed>
 */
function cxpTdsProbeBody(string $orderNumber): array
{
    return [
        'DeviceGuid' => (string) getenv('CONNEXPAY_SANDBOX_DEVICE_GUID'),
        'Amount' => CXP_TDS_PROBE_AMOUNT,
        'TenderType' => 'Credit',
        'OrderNumber' => $orderNumber,
        'Card' => [
            'CardHolderName' => 'Probe Tester',
            'CardNumber' => '4111111111111111',
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

const CXP_TDS_PROBE_VOLATILE = [
    'guid', 'timeStamp', 'orderNumber', 'authCode', 'refNumber', 'batchGuid',
    'customerReceipt', 'sequenceNumber', 'idSale', 'incomingTransactionCode',
    'cardTransactionIdentifier', 'invoiceNumber', 'omniscore', 'transactionId',
];

/**
 * @param  array<string, mixed>  $response
 * @return array<string, mixed>
 */
function cxpTdsProbeShape(array $response): array
{
    $shape = [];

    foreach ($response as $key => $value) {
        if (in_array($key, CXP_TDS_PROBE_VOLATILE, true)) {
            $shape[$key] = '(volatile)';

            continue;
        }

        $shape[$key] = is_array($value) ? cxpTdsProbeShape($value) : $value;
    }

    ksort($shape);

    return $shape;
}

/**
 * @param  array<string, mixed>  $a
 * @param  array<string, mixed>  $b
 * @return list<string>
 */
function cxpTdsProbeDriftKeys(array $a, array $b, string $prefix = ''): array
{
    $keys = [];

    foreach (array_unique([...array_keys($a), ...array_keys($b)]) as $key) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
        $left = $a[$key] ?? null;
        $right = $b[$key] ?? null;

        if (is_array($left) && is_array($right)) {
            $keys = [...$keys, ...cxpTdsProbeDriftKeys($left, $right, $path)];

            continue;
        }

        if ($left !== $right) {
            $keys[] = $path;
        }
    }

    return $keys;
}

/**
 * @param  array<string, mixed>  $card    merged into Card
 * @param  array<string, mixed>  $top     merged into the top-level body
 * @param  array<string, mixed>  $nested  merged into ConnexPayTransaction
 * @return array{outcome: string, detail: string, guid: ?string, blamed: list<string>, shape: array<string, mixed>, type: mixed}
 */
function cxpTdsProbeSend(int $case, array $card = [], array $top = [], array $nested = []): array
{
    $body = [...cxpTdsProbeBody(sprintf('TDSPROBE-%02d-%d', $case, time())), ...$top];
    $body['Card'] = [...$body['Card'], ...$card];

    if ($nested !== []) {
        $body['ConnexPayTransaction'] = $nested;
    }

    try {
        $response = cxpTdsProbeClient()->post('/api/v1/authonlys', $body);
    } catch (ClientException|ServerException $e) {
        // The validation message IS the evidence: ConnexPay returns ASP.NET
        // `modelState`, which names the property it objected to. That naming —
        // not the bare 400 — is the finding.
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
            'type' => null,
        ];
    } catch (Throwable $e) {
        return [
            'outcome' => 'ERROR',
            'detail' => $e::class.': '.$e->getMessage(),
            'guid' => null, 'blamed' => [], 'shape' => [], 'type' => null,
        ];
    }

    return [
        'outcome' => ($response['wasProcessed'] ?? null) === true ? 'ACCEPTED' : 'ACCEPTED-NOT-PROCESSED',
        'detail' => ($response['status'] ?? '(no status)')
            .' | '. ($response['processorResponseMessage'] ?? '(no processor message)')
            .' | amount='.json_encode($response['amount'] ?? null)
            // `type` is the field the first run of this probe caught moving: it
            // differed from the no-3DS baseline in exactly the cases carrying a
            // non-empty Cavv. Printing the value turns "something drifted" into
            // evidence about whether the attestation was acted on.
            .' | type='.json_encode($response['type'] ?? null),
        'guid' => isset($response['guid']) ? (string) $response['guid'] : null,
        'blamed' => [],
        'shape' => cxpTdsProbeShape($response),
        'type' => $response['type'] ?? null,
    ];
}

/** Did modelState name a property matching any of these needles? */
function cxpTdsProbeBlamed(array $result, string ...$needles): bool
{
    foreach ($result['blamed'] as $field) {
        if (array_any($needles, fn($needle) => stripos((string)$field, $needle) !== false)) {
            return true;
        }
    }

    return false;
}

/** Release the hold. Returns a one-line audit string for the ledger. */
function cxpTdsProbeVoid(?string $guid): string
{
    if ($guid === null || $guid === '') {
        return 'nothing to void';
    }

    $void = static fn (): array => cxpTdsProbeClient()->post('/api/v1/void', [
        'DeviceGuid' => (string) getenv('CONNEXPAY_SANDBOX_DEVICE_GUID'),
        'AuthOnlyGuid' => $guid,
    ]);

    try {
        return 'voided: '. ($void()['status'] ?? '(no status)');
    } catch (Throwable) {
        // Sandbox auths settle asynchronously; an immediate void can lose the
        // race. One retry, then report loudly so it can be voided by hand.
        sleep(8);

        try {
            return 'voided on retry: '. ($void()['status'] ?? '(no status)');
        } catch (Throwable $retry) {
            return '!! NOT VOIDED — void by hand in the CXP portal: '.$retry->getMessage();
        }
    }
}

it('probes which Card.ThreeDS and recurring fields the v1 acquiring model binds', function () {
    $valid = cxpTdsProbeThreeDS();

    $withoutEci = $valid;
    unset($withoutEci['ECI']);

    $withoutCavv = $valid;
    unset($withoutCavv['Cavv']);

    /** @var array<int, array{name: string, card: array<string, mixed>, top: array<string, mixed>, nested: array<string, mixed>}> $cases */
    $cases = [
        1 => ['name' => 'baseline (no ThreeDS)', 'card' => [], 'top' => [], 'nested' => []],

        // CONTROL+ : a field that certainly exists, wrong type. Must be REJECTED
        // naming Amount, else binding errors are invisible and every ACCEPTED
        // below is uninterpretable.
        2 => ['name' => 'CONTROL+ Amount = "not-a-number"', 'card' => [], 'top' => ['Amount' => 'not-a-number'], 'nested' => []],

        // CONTROL- : nonsense field inside Card, same wrong type as candidates.
        3 => ['name' => 'CONTROL- Card.ZzNoSuchField = {}', 'card' => ['ZzNoSuchField' => ['x' => 1]], 'top' => [], 'nested' => []],

        // Q1 — is the ThreeDS object itself bound, and which members?
        4 => ['name' => 'ThreeDS = "not-an-object"', 'card' => ['ThreeDS' => 'not-an-object'], 'top' => [], 'nested' => []],
        5 => ['name' => 'ThreeDS = full valid block', 'card' => ['ThreeDS' => $valid], 'top' => [], 'nested' => []],
        6 => ['name' => 'ThreeDS.Cavv = {}', 'card' => ['ThreeDS' => [...$valid, 'Cavv' => ['x' => 1]]], 'top' => [], 'nested' => []],
        7 => ['name' => 'ThreeDS.ECI = {}', 'card' => ['ThreeDS' => [...$valid, 'ECI' => ['x' => 1]]], 'top' => [], 'nested' => []],
        8 => ['name' => 'ThreeDS.Version = {}', 'card' => ['ThreeDS' => [...$valid, 'Version' => ['x' => 1]]], 'top' => [], 'nested' => []],
        9 => ['name' => 'ThreeDS.DirectoryServerTransactionID = {}', 'card' => ['ThreeDS' => [...$valid, 'DirectoryServerTransactionID' => ['x' => 1]]], 'top' => [], 'nested' => []],
        10 => ['name' => 'ThreeDS.AcsTransactionId = {}', 'card' => ['ThreeDS' => [...$valid, 'AcsTransactionId' => ['x' => 1]]], 'top' => [], 'nested' => []],
        // CONTROL- inside ThreeDS: if this is blamed too, the whole block is
        // strict about unknown members and cases 6-10 prove nothing individually.
        11 => ['name' => 'CONTROL- ThreeDS.ZzNoSuch = {}', 'card' => ['ThreeDS' => [...$valid, 'ZzNoSuch' => ['x' => 1]]], 'top' => [], 'nested' => []],

        // Q2 — exactly what formatThreeDS() emits for a non-successful
        // attestation today. A 200 here means the shift is silently lost.
        12 => ['name' => 'ThreeDS.ECI = null (what we send now)', 'card' => ['ThreeDS' => [...$valid, 'ECI' => null]], 'top' => [], 'nested' => []],
        13 => ['name' => 'ThreeDS.Cavv = null (what we send now)', 'card' => ['ThreeDS' => [...$valid, 'Cavv' => null]], 'top' => [], 'nested' => []],
        14 => ['name' => 'ThreeDS.Cavv = null AND ECI = null', 'card' => ['ThreeDS' => [...$valid, 'Cavv' => null, 'ECI' => null]], 'top' => [], 'nested' => []],
        15 => ['name' => 'ThreeDS with ECI omitted', 'card' => ['ThreeDS' => $withoutEci], 'top' => [], 'nested' => []],
        16 => ['name' => 'ThreeDS with Cavv omitted', 'card' => ['ThreeDS' => $withoutCavv], 'top' => [], 'nested' => []],

        // Q3 — does Card.IsRecurring exist on the wire?
        17 => ['name' => 'Card.IsRecurring = {} (type mismatch)', 'card' => ['IsRecurring' => ['x' => 1]], 'top' => [], 'nested' => []],
        18 => ['name' => 'Card.IsRecurring = true', 'card' => ['IsRecurring' => true], 'top' => [], 'nested' => []],

        // Q4 — anchor spellings for a subsequent transaction in the series.
        19 => ['name' => 'Card.SaleGuid = {}', 'card' => ['SaleGuid' => ['x' => 1]], 'top' => [], 'nested' => []],
        20 => ['name' => 'OriginalSaleGuid = {} (top level)', 'card' => [], 'top' => ['OriginalSaleGuid' => ['x' => 1]], 'nested' => []],
        21 => ['name' => 'ConnexPayTransaction.SaleGuid = {}', 'card' => [], 'top' => [], 'nested' => ['SaleGuid' => ['x' => 1]]],

        // Baseline again: proves nothing drifted mid-run.
        22 => ['name' => 'baseline repeated', 'card' => [], 'top' => [], 'nested' => []],
    ];

    $report = [];
    $ledger = [];

    foreach ($cases as $number => $case) {
        $result = cxpTdsProbeSend($number, $case['card'], $case['top'], $case['nested']);
        $result['name'] = $case['name'];

        if ($result['guid'] !== null) {
            $ledger[] = sprintf('case %02d %s -> %s', $number, $result['guid'], cxpTdsProbeVoid($result['guid']));
        }

        $report[$number] = $result;
    }

    $line = str_repeat('-', 118)."\n";
    fwrite(STDERR, "\n=== ConnexPay v1 /authonlys 3DS + recurring field probe (sandbox) ===\n".$line);
    foreach ($report as $number => $result) {
        fwrite(STDERR, sprintf(
            "%2d %-42s %-24s %s%s\n",
            $number,
            $result['name'],
            $result['outcome'],
            $result['detail'],
            $result['blamed'] === [] ? '' : ' [blamed: '.implode(', ', $result['blamed']).']',
        ));
    }
    fwrite(STDERR, $line."HOLDS CREATED AND RELEASED:\n");
    fwrite(STDERR, $ledger === [] ? "  (none)\n" : '  '.implode("\n  ", $ledger)."\n");

    $baseline = $report[1];
    $drift = [];
    foreach ($report as $number => $result) {
        if ($number === 1 || $result['shape'] === [] || $result['shape'] === $baseline['shape']) {
            continue;
        }

        $drift[$number] = cxpTdsProbeDriftKeys($baseline['shape'], $result['shape']);
    }

    if ($drift !== []) {
        fwrite(STDERR, $line."RESPONSE DRIFT vs baseline (keys whose value differs):\n");
        foreach ($drift as $number => $keys) {
            fwrite(STDERR, sprintf("  case %02d %-42s %s\n", $number, $report[$number]['name'], implode(', ', $keys) ?: '(none)'));
        }
    }

    // ── Findings, each stated as what the wire showed ────────────────────────
    fwrite(STDERR, $line."FINDINGS:\n");

    $controlPlusOk = $report[2]['outcome'] === 'REJECTED' && cxpTdsProbeBlamed($report[2], 'amount');
    $controlMinusOk = str_starts_with($report[3]['outcome'], 'ACCEPTED');
    $threeDSStrict = $report[11]['outcome'] === 'REJECTED';

    if (! $controlPlusOk) {
        fwrite(STDERR, "  !! CONTROL+ did not produce a modelState error naming Amount. Binding errors are not readable\n"
            ."     here, so NO row above is evidence of absence. Everything below is void.\n");
    } else {
        fwrite(STDERR, "  CONTROL+ ok (Amount blamed) — binding errors are readable.\n");
        fwrite(STDERR, '  CONTROL- '.($controlMinusOk ? 'ok (unknown Card field accepted) — unknown properties are dropped silently.'
            : 'UNEXPECTED: a nonsense Card field was rejected; this model is strict, read rows individually.')."\n");

        // Q1
        $bound = [];
        $unbound = [];
        foreach ([6 => 'Cavv', 7 => 'ECI', 8 => 'Version', 9 => 'DirectoryServerTransactionID', 10 => 'AcsTransactionId'] as $n => $field) {
            if ($report[$n]['outcome'] === 'REJECTED') {
                $bound[] = $field;
            } else {
                $unbound[] = $field;
            }
        }
        fwrite(STDERR, '  Q1 ThreeDS members BOUND: '.($bound === [] ? '(none)' : implode(', ', $bound))."\n");
        fwrite(STDERR, '     ThreeDS members NOT bound (silently dropped): '.($unbound === [] ? '(none)' : implode(', ', $unbound))."\n");
        if ($threeDSStrict) {
            fwrite(STDERR, "     NOTE: the ThreeDS control- was ALSO rejected, so the block rejects unknown members too —\n"
                ."     a rejection above does not by itself prove the member is known. Read the blamed names.\n");
        }
        fwrite(STDERR, '     ThreeDS object bound at all: '.($report[4]['outcome'] === 'REJECTED' ? 'YES (a scalar where the object belongs was rejected)' : 'NO EVIDENCE')."\n");
        fwrite(STDERR, '     Full valid block: '.$report[5]['outcome']."\n");

        // Q2 — the one that decides whether our adapter needs Nuvei's guard.
        $nullCases = [12 => 'ECI=null', 13 => 'Cavv=null', 14 => 'both null', 15 => 'ECI omitted', 16 => 'Cavv omitted'];
        $rejectedNulls = [];
        foreach ($nullCases as $n => $label) {
            if ($report[$n]['outcome'] === 'REJECTED') {
                $rejectedNulls[] = $label;
            }
        }
        if ($rejectedNulls !== []) {
            fwrite(STDERR, '  Q2 ConnexPay REJECTS an incomplete attestation ('.implode('; ', $rejectedNulls)."):\n"
                ."     formatThreeDS() can emit exactly that today, so it needs the same guard as Nuvei —\n"
                ."     refuse with IncompleteAuthentication before sending, not a decline after.\n");
        } else {
            fwrite(STDERR, "  Q2 ConnexPay ACCEPTS an incomplete attestation (null/omitted Cavv or ECI) without complaint.\n"
                ."     That is the WORSE outcome: no error to notice, and the liability shift is simply not claimed.\n"
                ."     A domain-side invariant is the only place left to catch it.\n");
        }

        // Q2b — does a forwarded CAVV visibly change the transaction? The drift
        // table only says a key moved; this reads its value, and it is the one
        // signal here that speaks to whether the attestation is ACTED ON rather
        // than merely parsed.
        $withCavv = [5 => 'full valid', 12 => 'ECI=null', 15 => 'ECI omitted'];
        $withoutCavv = [13 => 'Cavv=null', 14 => 'both null', 16 => 'Cavv omitted'];
        $describe = static fn (array $set): string => implode(', ', array_map(
            static fn (int $n) => $report[$n]['name'].' -> type='.json_encode($report[$n]['type']),
            array_keys($set),
        ));
        fwrite(STDERR, '  Q2b baseline (no ThreeDS at all) type='.json_encode($baseline['type'])."\n");
        fwrite(STDERR, '      Cavv PRESENT:  '.$describe($withCavv)."\n");
        fwrite(STDERR, '      Cavv ABSENT:   '.$describe($withoutCavv)."\n");

        $cavvTypes = array_unique(array_map(fn (int $n) => json_encode($report[$n]['type']), array_keys($withCavv)));
        $noCavvTypes = array_unique(array_map(fn (int $n) => json_encode($report[$n]['type']), array_keys($withoutCavv)));
        if (count($cavvTypes) === 1 && count($noCavvTypes) === 1 && $cavvTypes !== $noCavvTypes
            && $noCavvTypes === [json_encode($baseline['type'])]) {
            fwrite(STDERR, "      => The CAVV is ACTED ON: every Cavv-bearing call reports one type, and every call with a\n"
                ."         null or omitted Cavv reports the SAME type as a request that sent no ThreeDS block at all.\n"
                ."         So an incomplete attestation is not just accepted, it is processed as UNAUTHENTICATED.\n");
        }

        // Q3
        fwrite(STDERR, '  Q3 Card.IsRecurring bound: '.(cxpTdsProbeBlamed($report[17], 'recurring')
            ? 'YES — modelState named it. The field is real.'
            : ($report[17]['outcome'] === 'REJECTED' ? 'REJECTED but not by name — read the blamed list.' : 'NO EVIDENCE — accepted as an object, i.e. dropped like any unknown field.'))."\n");
        fwrite(STDERR, '     IsRecurring = true: '.$report[18]['outcome']."\n");

        // Q4
        // A bare 400 is NOT evidence of binding: the sandbox also returns 400 with
        // an empty modelState on transient failures, and an early run of this probe
        // mis-read one of those as "OriginalSaleGuid binds". Require the property to
        // be NAMED, and report an unnamed rejection as the noise it is.
        $anchors = [];
        $noisy = [];
        foreach ([19 => 'SaleGuid', 20 => 'OriginalSaleGuid', 21 => 'SaleGuid'] as $n => $needle) {
            if ($report[$n]['outcome'] !== 'REJECTED') {
                continue;
            }

            if (cxpTdsProbeBlamed($report[$n], $needle)) {
                $anchors[] = $report[$n]['name'].' [blamed: '.implode(',', $report[$n]['blamed']).']';
            } else {
                $noisy[] = $report[$n]['name'].' (rejected, nothing named — transient, re-run before believing it)';
            }
        }
        fwrite(STDERR, '  Q4 recurring anchor spellings that bind: '.($anchors === [] ? '(none of the three) — the property name must come from ConnexPay.' : implode('; ', $anchors))."\n");
        if ($noisy !== []) {
            fwrite(STDERR, '     UNINTERPRETABLE: '.implode('; ', $noisy)."\n");
        }
    }

    fwrite(STDERR, $line."SCOPE: one sandbox merchant account, /api/v1/authonlys only. A property this account does not bind\n"
        ."       could still be bound for a differently provisioned account. Binding is all a type-mismatch case can\n"
        ."       show — EXCEPT for the CAVV, where the response `type` (Secured3D vs Default) does reveal that the\n"
        ."       value was acted on. No other field here has such a tell, so for the rest, parsed != honoured.\n".$line."\n");

    // The only assertion is that the baseline worked and did not drift; every
    // other row is a finding about the vendor, not a property of our code, and
    // must not turn a run red.
    expect($baseline['outcome'])->toBe('ACCEPTED', 'baseline AuthOnly failed, probe not interpretable: '.$baseline['detail'])
        ->and($report[22]['outcome'])->toBe($baseline['outcome'], 'baseline drifted mid-run; results above are not comparable');
})->skip(! cxpTdsProbeConfigured(), CXP_TDS_PROBE_SKIP);
