<?php

declare(strict_types=1);

use Techork\PaymentService\ConnexPay\ConnexPayDisputesClientInterface;
use Techork\PaymentService\ConnexPay\ConnexPayGateway;
use Techork\PaymentService\Gateway\Command\DisputeCaseQuery;
use Techork\PaymentService\Gateway\Role\ConcedesDisputes;
use Techork\PaymentService\Gateway\Role\ReadsDisputeCases;
use Techork\PaymentService\Gateway\Role\SubmitsDisputeEvidence;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * The ConnexPay driver's dispute surface: one role, and the endpoint its delegation reaches.
 *
 * ## What is asserted, and what deliberately is not
 *
 * The roles stand outside the `Gateway` composite — acquiring does not imply a dispute surface — so
 * nothing in an interface would fail if this driver declared a role and never implemented it, or
 * declared a role it has no call for. Three of the four assertions below are that check, and the
 * fourth is that the delegation for the one role it does declare reaches the CMS read rather than
 * some other operation.
 *
 * **The two roles this driver does not declare are asserted as absent, and that is F9's substance,
 * not padding.** `SubmitsDisputeEvidence` would need an API call ConnexPay does not have — F9's
 * third deliverable is the *absence* of that port implementation, and a role declared without a
 * call behind it is exactly how an absence gets filled in later by accident.
 *
 * ## No HTTP
 *
 * `ConnexPayDisputesClientInterface` is faked, and the gateway's own client is replaced through
 * `setDisputesClient()`. The credential the gateway builds its CMS client from in `configure()` —
 * `disputesUsername` / `disputesPassword` — is named there and asserted nowhere, because the built
 * `ConnexPayDisputesClient` exposes no seam to read it back and reaching ConnexPay to find out is
 * not something a test may do. What this file can prove is the delegation, and it does.
 */

/** The transport for this file: it records the paths it was asked for and answers one case. */
final class CmsGatewayReadClient implements ConnexPayDisputesClientInterface
{
    /** @var list<string> */
    public array $paths = [];

    public function __construct(private ?array $case = null) {}

    public function get(string $path, array $query): array
    {
        $this->paths[] = $path;

        return $this->case === null ? [] : [$this->case];
    }
}

/** One CMS case payload, shaped as the documented sample: a case the merchant must answer. */
function cmsGatewayCase(string $number = 'CB-1004'): array
{
    return [
        'CaseNumber' => $number,
        'ResolutionTo' => 'M',
        'CaseType' => 1,
        'CardBrand' => 1,
        'ReasonCode' => '10.4',
        'DueDate' => '2026-09-24T00:00:00',
        'HasResponse' => false,
        'NetPosition' => -60.0,
    ];
}

function cmsGateway(): ConnexPayGateway
{
    $gateway = new ConnexPayGateway;
    $gateway->configure(cpInfrastructure([
        'settings' => [
            'username' => 'a-user',
            'password' => 'a-password',
            // The CMS credentials are separate from the CRM ones — F9's read is a different user
            // with a different grant — and the gateway reads them here.
            'disputesUsername' => 'cms-user',
            'disputesPassword' => 'cms-password',
        ],
    ]));

    return $gateway;
}

it('declares the dispute read role, and only that one', function () {
    $gateway = cmsGateway();

    expect($gateway)->toBeInstanceOf(ReadsDisputeCases::class)
        // No call on which a response could be filed, so no role for one: the absence is the
        // statement, and a stub here would be a promise the provider cannot keep.
        ->and($gateway)->not->toBeInstanceOf(SubmitsDisputeEvidence::class)
        // No close call either — the CMS API is read-only — which is why no reading of this
        // provider can be `concedable`.
        ->and($gateway)->not->toBeInstanceOf(ConcedesDisputes::class);
});

it('reads the case through the disputes client, over the CMS window', function () {
    $client = new CmsGatewayReadClient(cmsGatewayCase());
    $gateway = cmsGateway();
    $gateway->setDisputesClient($client);

    $reading = $gateway->readDisputeCase(new DisputeCaseQuery(
        gatewayId: GatewayId::generate(),
        disputeReference: 'CB-1004',
    ));

    expect($client->paths)->toBe(['/api/Chargeback/GetByUser'])
        ->and($reading->awaitingResponse)->toBeTrue()
        ->and($reading->cardBrand)->toBe('visa')
        ->and($reading->reasonCode)->toBe('10.4')
        ->and($reading->concedable)->toBeFalse();
});

/**
 * A case the provider did not return is refused, not answered as "nothing is waiting on us". This
 * is the end-to-end shape of the mistake the whole action model exists to prevent, asserted through
 * the driver because the driver is what the adapter above it holds.
 */
it('refuses rather than answering for a case the CMS did not return', function () {
    $gateway = cmsGateway();
    $gateway->setDisputesClient(new CmsGatewayReadClient);

    expect(fn () => $gateway->readDisputeCase(new DisputeCaseQuery(
        gatewayId: GatewayId::generate(),
        disputeReference: 'CB-9999',
    )))->toThrow(RuntimeException::class, 'CB-9999');
});
