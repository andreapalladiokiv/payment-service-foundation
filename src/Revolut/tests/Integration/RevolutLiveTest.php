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
 *   REVOLUT_CLIENT_ID=... REVOLUT_PRIVATE_KEY=... REVOLUT_REFRESH_TOKEN=... \
 *   REVOLUT_ISSUER=... REVOLUT_PROD_HOLDER_ID=... \
 *   vendor/bin/pest src/Revolut/tests/Integration/RevolutLiveTest.php
 *
 * to issue a £1.00 card against the live API (it will be a real card —
 * terminate it afterwards). The client performs the JWT client-assertion
 * token exchange itself, so real OAuth credentials are required.
 */
const REVOLUT_LIVE_SKIP = 'Revolut has no virtual-card Sandbox; set REVOLUT_CLIENT_ID + REVOLUT_PRIVATE_KEY + REVOLUT_REFRESH_TOKEN + REVOLUT_ISSUER + REVOLUT_PROD_HOLDER_ID to run the Production smoke test (issues a real card).';

function revolutLiveConfigured(): bool
{
    foreach (['REVOLUT_CLIENT_ID', 'REVOLUT_PRIVATE_KEY', 'REVOLUT_REFRESH_TOKEN', 'REVOLUT_ISSUER', 'REVOLUT_PROD_HOLDER_ID'] as $var) {
        if ((getenv($var) ?: '') === '') {
            return false;
        }
    }

    return true;
}

it('issues a virtual card against the live Revolut API', function () {
    $client = new RevolutClient(
        clientId: (string) getenv('REVOLUT_CLIENT_ID'),
        privateKey: (string) getenv('REVOLUT_PRIVATE_KEY'),
        refreshToken: (string) getenv('REVOLUT_REFRESH_TOKEN'),
        issuer: (string) getenv('REVOLUT_ISSUER'),
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
