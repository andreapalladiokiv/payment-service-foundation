<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\CreditCard\CardSummary;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\Email;
use Techork\PaymentService\Common\ValueObject\IpAddress;
use Techork\PaymentService\Common\ValueObject\ConnectionContext;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Forter\ForterClient;
use Techork\PaymentService\Forter\ForterFraudScreeningProvider;
use Techork\PaymentService\Forter\FraudDecision;
use Techork\PaymentService\Forter\FraudScreeningRequest;

/**
 * Live integration test against Forter's TEST environment (their own sandbox — separate
 * site ID and secret key from the portal, same API hosts). Skipped unless credentials
 * are provided:
 *
 *   FORTER_SANDBOX_SECRET_KEY=... \
 *   FORTER_SANDBOX_SITE_ID=... \
 *   [FORTER_SANDBOX_BASE_URL=...] \
 *   vendor/bin/pest src/Forter/tests/Integration/ForterSandboxTest.php
 *
 * THE VENDOR QUESTION THIS RECORDS. Forter documents a sandbox and documents
 * triggers (docs.forter.com/test-cases): an accountOwner email of
 * `approve@forter.com` yields action=approve. The baseline asserts exactly that —
 * so the test is a live check that the v2 transport, the Basic-auth header shape
 * (`Authorization: Basic base64(secret:)`), the api-version header and the
 * order-payload mapping are still what Forter accepts, with only the baseline
 * asserted, as everywhere in this suite.
 *
 * The base URL is env-overridable because which host the TEST site scores on is a
 * vendor question (`ForterClient::PRODUCTION_BASE_URL` is the only host the
 * production code knows; the current docs advertise api.forter-secure.com). A
 * run that needed to override it reports the mapping, not a product claim.
 *
 * SAFETY. Screening is a read of a fraud verdict, not a payment: nothing is
 * settled, held, or created beyond the order record Forter itself keeps for the
 * TEST site. The reference is unique per run so the order is findable.
 */
const FORTER_SANDBOX_SKIP = 'Set FORTER_SANDBOX_SECRET_KEY / FORTER_SANDBOX_SITE_ID to run the Forter sandbox integration test.';

function forterSandboxConfigured(): bool
{
    return (getenv('FORTER_SANDBOX_SECRET_KEY') ?: '') !== ''
        && (getenv('FORTER_SANDBOX_SITE_ID') ?: '') !== '';
}

it('screens an order and gets the documented sandbox approval trigger', function () {
    $reference = 'forter-live-'.time();

    // The documented sandbox trigger set: this email makes the TEST site approve.
    $result = new ForterFraudScreeningProvider(
        new ForterClient(
            (string) getenv('FORTER_SANDBOX_SECRET_KEY'),
            (string) (getenv('FORTER_SANDBOX_BASE_URL') ?: ForterClient::PRODUCTION_BASE_URL),
            (string) getenv('FORTER_SANDBOX_SITE_ID'),
        ),
    )->screen(new FraudScreeningRequest(
        reference: $reference,
        card: new CardSummary('522222', '0005', CardBrand::Mastercard, Expiration::fromMonthAndYear(12, 2030), new Holder('Foundation Test')),
        customer: forterSuiteCustomer(
            firstName: 'Foundation',
            lastName: 'Test',
            email: new Email('approve@forter.com'),
        ),
        amountMinorUnits: 1099,
        currencyCode: 'USD',
        connection: new ConnectionContext(new IpAddress('203.0.113.7'), 'Mozilla/5.0'),
    ));

    expect($result->decision)->toBe(FraudDecision::Approve, sprintf(
        'the sandbox approval trigger was not honored (decision %s, reasonCode %s) — the payload, the auth header '
        .'or the API version the production code sends is no longer what Forter accepts',
        $result->decision->value,
        $result->reasonCode ?? '(none)',
    ))
        ->and($result->reference)->toBe($reference);
})->skip(! forterSandboxConfigured(), FORTER_SANDBOX_SKIP);