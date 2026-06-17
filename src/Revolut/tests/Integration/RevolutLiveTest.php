<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Techork\PaymentService\Revolut\IssueVirtualCardRequest;
use Techork\PaymentService\Revolut\RevolutClient;

/**
 * Live integration coverage for Revolut card issuing.
 *
 * Revolut has NO Sandbox for virtual cards — create-card, update, terminate
 * and sensitive-card-data exist only in Production, which issues a real card
 * against a real account and therefore cannot run unattended in CI.
 *
 * The unit suite (mocked {@see \Techork\PaymentService\Revolut\RevolutHttpClientInterface})
 * exercises the request/response mapping exhaustively. This test documents
 * the constraint and provides a Production-gated smoke check: set
 *
 *   REVOLUT_PROD_ACCESS_TOKEN=... REVOLUT_PROD_HOLDER_ID=... \
 *   vendor/bin/pest src/Revolut/tests/Integration/RevolutLiveTest.php
 *
 * to issue a £1.00 card against the live API (it will be a real card —
 * terminate it afterwards).
 */
const REVOLUT_LIVE_SKIP = 'Revolut has no virtual-card Sandbox; set REVOLUT_PROD_ACCESS_TOKEN + REVOLUT_PROD_HOLDER_ID to run the Production smoke test (issues a real card).';

function revolutLiveConfigured(): bool
{
    return (getenv('REVOLUT_PROD_ACCESS_TOKEN') ?: '') !== ''
        && (getenv('REVOLUT_PROD_HOLDER_ID') ?: '') !== '';
}

it('issues a virtual card against the live Revolut API', function () {
    $client = new RevolutClient(
        accessToken: (string) getenv('REVOLUT_PROD_ACCESS_TOKEN'),
        baseUrl: RevolutClient::PRODUCTION_BASE_URL,
    );

    $request = new IssueVirtualCardRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'revolutClient' => $client,
        'holderId' => (string) getenv('REVOLUT_PROD_HOLDER_ID'),
        'money' => new Money(100, new Currency('GBP')),
        'label' => 'Foundation smoke test',
        'fetchSensitiveDetails' => false,
    ]);

    $result = $request->send()->toVirtualCardResult();

    expect($result->success)->toBeTrue($result->message ?? 'issuance failed')
        ->and($result->cardGuid)->not->toBeEmpty();
})->skip(! revolutLiveConfigured(), REVOLUT_LIVE_SKIP);
