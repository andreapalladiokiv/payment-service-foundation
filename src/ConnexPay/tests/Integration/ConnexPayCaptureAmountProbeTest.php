<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use Techork\PaymentService\ConnexPay\ConnexPayClient;

/**
 * PROBE, not a regression test. One question, asked because a documented "no" has
 * already turned out to be an incomplete "no" on this API once.
 *
 * DOES /api/v1/Captures ACCEPT AN AMOUNT? Their guide says not — "You can only
 * capture the original amount that was authorized … If the captured amount needs to
 * be for a different amount, you'll need to void the Authorization and run a new Sale
 * or another Auth + Capture" — and our CaptureRequest sends none. On that premise
 * ConnexPayGateway::capture() emulates a partial capture by voiding and re-selling,
 * and needs `authorizedAmount` purely to detect that a partial one was asked for.
 *
 * If the field turns out to bind and be honoured, that whole emulation is
 * unnecessary and so is the detection. If it does not, the emulation is their own
 * prescribed procedure and the only real gap is that OmnipayCapturePort never passes
 * the arguments that reach it.
 *
 * HOW IT READS. Type mismatch first, because it settles nothing: an Amount sent as a
 * JSON object is rejected by name if the model binds it, and ignored if it does not.
 * Only then a real 0.50-of-1.00 capture, whose response amount says whether a bound
 * field was also honoured.
 *
 * SAFETY. Sandbox only. Each case authorizes 1.00 and, where the case captures,
 * SETTLES it — unlike the sibling probes, which only ever held and voided. Every sale
 * created is reversed: void first, falling back to /api/v1/returns for anything the
 * sandbox already settled, and the run prints a ledger of both. OrderNumbers are
 * CAPAMT-<n>-<ts>.
 *
 * Run:
 *   CONNEXPAY_SANDBOX_USERNAME=... CONNEXPAY_SANDBOX_PASSWORD=... \
 *   CONNEXPAY_SANDBOX_DEVICE_GUID=... \
 *   vendor/bin/pest src/ConnexPay/tests/Integration/ConnexPayCaptureAmountProbeTest.php
 */
const CXP_CAPAMT_SKIP = 'Set CONNEXPAY_SANDBOX_USERNAME / _PASSWORD / _DEVICE_GUID to run the ConnexPay capture-amount probe.';

const CXP_CAPAMT_AUTHORIZED = 1.00;

const CXP_CAPAMT_PARTIAL = 0.50;

function cxpCapAmtConfigured(): bool
{
    return (getenv('CONNEXPAY_SANDBOX_USERNAME') ?: '') !== ''
        && (getenv('CONNEXPAY_SANDBOX_PASSWORD') ?: '') !== ''
        && (getenv('CONNEXPAY_SANDBOX_DEVICE_GUID') ?: '') !== '';
}

function cxpCapAmtClient(): ConnexPayClient
{
    static $client = null;

    return $client ??= new ConnexPayClient(
        username: (string) getenv('CONNEXPAY_SANDBOX_USERNAME'),
        password: (string) getenv('CONNEXPAY_SANDBOX_PASSWORD'),
        environment: 'sandbox',
    );
}

/**
 * @return array{ok: bool, detail: string, body: array<string, mixed>, blamed: list<string>}
 */
function cxpCapAmtPost(string $path, array $body): array
{
    try {
        $response = cxpCapAmtClient()->post($path, $body);

        return ['ok' => true, 'detail' => (string) ($response['status'] ?? '(no status)'), 'body' => $response, 'blamed' => []];
    } catch (ClientException|ServerException $e) {
        $raw = (string) $e->getResponse()->getBody();
        $decoded = json_decode($raw, true);
        $modelState = is_array($decoded) && is_array($decoded['modelState'] ?? null) ? $decoded['modelState'] : [];

        return [
            'ok' => false,
            'detail' => 'HTTP '.$e->getResponse()->getStatusCode().': '.preg_replace('/\s+/', ' ', mb_substr($raw, 0, 300)),
            'body' => [],
            'blamed' => array_map('strval', array_keys($modelState)),
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'detail' => $e::class.': '.$e->getMessage(), 'body' => [], 'blamed' => []];
    }
}

/** An AuthOnly for the full amount, mirroring what AuthorizeRequest builds. */
function cxpCapAmtAuthorize(int $case): array
{
    return cxpCapAmtPost('/api/v1/authonlys', [
        'DeviceGuid' => (string) getenv('CONNEXPAY_SANDBOX_DEVICE_GUID'),
        'Amount' => CXP_CAPAMT_AUTHORIZED,
        'TenderType' => 'Credit',
        'OrderNumber' => sprintf('CAPAMT-%02d-%d', $case, time()),
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
    ]);
}

/** Exactly what CaptureRequest posts, plus whatever the case adds. */
function cxpCapAmtCapture(string $authGuid, int $case, array $extra = []): array
{
    return cxpCapAmtPost('/api/v1/Captures', [
        'DeviceGuid' => (string) getenv('CONNEXPAY_SANDBOX_DEVICE_GUID'),
        'AuthOnlyGuid' => $authGuid,
        'OrderNumber' => sprintf('CAPAMT-%02d-cap-%d', $case, time()),
        'ConnexPayTransaction' => ['ExpectedPayments' => 1],
        ...$extra,
    ]);
}

/**
 * Reverses a sale.
 *
 * `SaleGuid` + `Amount`, which is the shape
 * {@see \Techork\PaymentService\ConnexPay\RefundRequest::voidUnsettledSale} documents
 * /void as accepting. The first version of this probe sent `AuthOnlyGuid` instead and
 * every reversal answered "Transaction not found for the given GUID/ReferenceNumber",
 * while /returns answered "Sale has not been settled" — a captured sale sits between
 * the two, and only the right key gets it back.
 */
function cxpCapAmtReverse(string $saleGuid, float $amount): string
{
    $void = cxpCapAmtPost('/api/v1/void', [
        'DeviceGuid' => (string) getenv('CONNEXPAY_SANDBOX_DEVICE_GUID'),
        'SaleGuid' => $saleGuid,
        'Amount' => $amount,
    ]);

    if ($void['ok']) {
        return 'voided: '.$void['detail'];
    }

    // Settled already, so a void is refused and a return is the reversal.
    $return = cxpCapAmtPost('/api/v1/returns', [
        'DeviceGuid' => (string) getenv('CONNEXPAY_SANDBOX_DEVICE_GUID'),
        'SaleGuid' => $saleGuid,
        'Amount' => $amount,
    ]);

    return $return['ok']
        ? 'returned '.$amount.': '.$return['detail']
        : '!! NOT REVERSED — reverse by hand (OrderNumber CAPAMT-*): void='.$void['detail'].' return='.$return['detail'];
}

it('probes whether /api/v1/Captures binds and honours an Amount', function () {
    $line = str_repeat('-', 118)."\n";
    $ledger = [];
    $report = [];

    // ── Case 1: our own body, no Amount. Establishes what a plain capture does.
    $auth1 = cxpCapAmtAuthorize(1);
    $guid1 = $auth1['body']['guid'] ?? null;

    if (! $auth1['ok'] || $guid1 === null) {
        fwrite(STDERR, "\nABORTED — the baseline AuthOnly failed: {$auth1['detail']}\n");
        expect($auth1['ok'])->toBeTrue('baseline AuthOnly failed: '.$auth1['detail']);

        return;
    }

    $cap1 = cxpCapAmtCapture((string) $guid1, 1);
    $sale1 = $cap1['body']['sale'] ?? $cap1['body'];
    $report[1] = [
        'name' => 'capture as we send it (no Amount)',
        'outcome' => $cap1['ok'] ? 'ACCEPTED' : 'REJECTED',
        'detail' => $cap1['detail'].' | captured amount='.json_encode($sale1['amount'] ?? null),
    ];
    $ledger[] = 'case 1 sale '.($sale1['guid'] ?? $guid1).' -> '.cxpCapAmtReverse((string) ($sale1['guid'] ?? $guid1), (float) ($sale1['amount'] ?? CXP_CAPAMT_AUTHORIZED));

    // ── Case 2: Amount as an object. Settles nothing either way, and a rejection
    //    naming Amount is the only proof the model binds it.
    $auth2 = cxpCapAmtAuthorize(2);
    $guid2 = $auth2['body']['guid'] ?? null;
    $cap2 = $guid2 === null
        ? ['ok' => false, 'detail' => 'auth failed: '.$auth2['detail'], 'body' => [], 'blamed' => []]
        : cxpCapAmtCapture((string) $guid2, 2, ['Amount' => ['x' => 1]]);
    $sale2 = $cap2['body']['sale'] ?? $cap2['body'];
    $report[2] = [
        'name' => 'Amount = {} (type mismatch)',
        'outcome' => $cap2['ok'] ? 'ACCEPTED' : 'REJECTED',
        'detail' => $cap2['detail'].($cap2['blamed'] === [] ? '' : ' [blamed: '.implode(', ', $cap2['blamed']).']'),
    ];
    if ($guid2 !== null) {
        $ledger[] = 'case 2 '.($sale2['guid'] ?? $guid2).' -> '.cxpCapAmtReverse((string) ($sale2['guid'] ?? $guid2), (float) ($sale2['amount'] ?? CXP_CAPAMT_AUTHORIZED));
    }

    // ── Case 3: a real partial. The response amount is the answer.
    $auth3 = cxpCapAmtAuthorize(3);
    $guid3 = $auth3['body']['guid'] ?? null;
    $cap3 = $guid3 === null
        ? ['ok' => false, 'detail' => 'auth failed: '.$auth3['detail'], 'body' => [], 'blamed' => []]
        : cxpCapAmtCapture((string) $guid3, 3, ['Amount' => CXP_CAPAMT_PARTIAL]);
    $sale3 = $cap3['body']['sale'] ?? $cap3['body'];
    $captured3 = $sale3['amount'] ?? null;
    $report[3] = [
        'name' => 'Amount = 0.50 of 1.00 authorized',
        'outcome' => $cap3['ok'] ? 'ACCEPTED' : 'REJECTED',
        'detail' => $cap3['detail'].' | captured amount='.json_encode($captured3),
    ];
    if ($guid3 !== null) {
        $ledger[] = 'case 3 '.($sale3['guid'] ?? $guid3).' -> '.cxpCapAmtReverse((string) ($sale3['guid'] ?? $guid3), (float) ($captured3 ?? CXP_CAPAMT_AUTHORIZED));
    }

    fwrite(STDERR, "\n=== ConnexPay /api/v1/Captures amount probe (sandbox) ===\n".$line);
    foreach ($report as $n => $row) {
        fwrite(STDERR, sprintf("%2d %-38s %-10s %s\n", $n, $row['name'], $row['outcome'], $row['detail']));
    }
    fwrite(STDERR, $line."SALES CREATED AND REVERSED:\n  ".implode("\n  ", $ledger)."\n".$line);

    fwrite(STDERR, "FINDING:\n");

    $blamedAmount = array_any($report[2]['detail'] === null ? [] : $cap2['blamed'], fn (string $f): bool => stripos($f, 'amount') !== false);

    if ($blamedAmount) {
        fwrite(STDERR, "  Amount IS bound on /api/v1/Captures — modelState named it. Their guide's \"you can only\n"
            ."  capture the original amount\" describes a rule, not an absent field; read case 3 for whether a\n"
            ."  bound field is also honoured.\n");
    } else {
        fwrite(STDERR, "  Amount is NOT bound: an object where a number would go was ignored rather than rejected,\n"
            ."  so the field does not exist on this request.\n");
    }

    if ($captured3 !== null && abs(((float) $captured3) - CXP_CAPAMT_PARTIAL) < 0.001) {
        fwrite(STDERR, "  AND HONOURED — 0.50 of a 1.00 hold was captured. ConnexPay does partial capture natively,\n"
            ."  so the void-and-resell emulation in ConnexPayGateway::capture() is unnecessary, and so is the\n"
            ."  authorizedAmount it needs only to detect a partial request.\n");
    } elseif ($captured3 !== null) {
        fwrite(STDERR, '  NOT HONOURED — asked for '.CXP_CAPAMT_PARTIAL.', captured '.json_encode($captured3).". The guide is right, the\n"
            ."  emulation is their own prescribed procedure, and the only defect is that OmnipayCapturePort\n"
            ."  never passes the arguments that would trigger it — so a partial request takes the full hold.\n");
    } else {
        fwrite(STDERR, "  Case 3 produced no amount to read; the question is unanswered.\n");
    }

    fwrite(STDERR, $line."SCOPE: one sandbox account. A field this account ignores could still be bound elsewhere, and a\n"
        ."       sandbox that honours something says nothing about how the acquirer settles it in production.\n".$line."\n");

    expect($report[1]['outcome'])->toBe('ACCEPTED', 'the plain capture failed, so nothing above is comparable');
})->skip(! cxpCapAmtConfigured(), CXP_CAPAMT_SKIP);
