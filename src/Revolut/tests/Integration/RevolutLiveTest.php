<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Gateway\Command\IssueCardCommand;
use Techork\PaymentService\Gateway\ValueObject\CardSpendCategory;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Revolut\CardSettings;
use Techork\PaymentService\Revolut\IssueVirtualCard;
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
 *   REVOLUT_ISSUER=... \
 *   vendor/bin/pest src/Revolut/tests/Integration/RevolutLiveTest.php
 *
 * to issue a £1.00 card against the live API (it will be a real card —
 * terminate it afterwards). The client performs the JWT client-assertion
 * token exchange itself, so real OAuth credentials are required.
 *
 * SAFETY. The card is issued with a £1.00 spend limit and no sensitive details
 * are fetched (that endpoint needs READ_SENSITIVE_CARD_DATA and an allow-listed
 * IP — and a smoke test has no business reading a PAN anyway).
 */
const REVOLUT_LIVE_SKIP = 'Revolut has no virtual-card Sandbox; set REVOLUT_CLIENT_ID + REVOLUT_PRIVATE_KEY + REVOLUT_REFRESH_TOKEN + REVOLUT_ISSUER to run the Production smoke test (issues a real card).';

function revolutLiveConfigured(): bool
{
    return array_all(['REVOLUT_CLIENT_ID', 'REVOLUT_PRIVATE_KEY', 'REVOLUT_REFRESH_TOKEN', 'REVOLUT_ISSUER'], fn($var) => (getenv($var) ?: '') !== '');
}

it('issues a virtual card against the live Revolut API', function () {
    $client = new RevolutClient(
        clientId: (string) getenv('REVOLUT_CLIENT_ID'),
        privateKey: (string) getenv('REVOLUT_PRIVATE_KEY'),
        refreshToken: (string) getenv('REVOLUT_REFRESH_TOKEN'),
        issuer: (string) getenv('REVOLUT_ISSUER'),
        baseUrl: RevolutClient::PRODUCTION_BASE_URL,
    );

    $result = new IssueVirtualCard(
        $client,
        new CardSettings(fetchSensitiveDetails: false),
    )->issue(new IssueCardCommand(
        gatewayId: GatewayId::generate(),
        transactionReference: 'live-smoke',
        amountLimit: new Money(100, new Currency('GBP')),
        spendCategory: CardSpendCategory::TravelAir,
    ));

    expect($result->success)->toBeTrue($result->message ?? 'issuance failed')
        ->and($result->cardGuid)->not->toBeEmpty();
})->skip(! revolutLiveConfigured(), REVOLUT_LIVE_SKIP);