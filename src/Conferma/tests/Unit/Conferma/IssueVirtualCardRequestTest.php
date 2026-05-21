<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Techork\PaymentService\Conferma\ConfermaHttpClientInterface;
use Techork\PaymentService\Conferma\IssueVirtualCardRequest;
use Techork\PaymentService\Conferma\IssueVirtualCardResponse;
use Techork\PaymentService\Gateway\ValueObject\CardSpendCategory;

function makeIssueRequest(array $overrides = []): IssueVirtualCardRequest
{
    $client = Mockery::mock(ConfermaHttpClientInterface::class);

    $request = new IssueVirtualCardRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'confermaClient' => $client,
        'clientAccountType' => 'CLIENTID',
        'clientAccountValue' => '1234',
        'defaultSupplierName' => 'My Supplier',
        'paymentRangeDays' => 30,
        'money' => new Money(15000, new Currency('GBP')),
        'spendCategory' => CardSpendCategory::TravelAir->value,
        'transactionReference' => 'pi_123',
        'firstName' => 'John',
        'lastName' => 'Doe',
        ...$overrides,
    ]);

    return $request;
}

it('builds a Conferma deployments body with all required fields', function () {
    $data = makeIssueRequest()->getData();

    expect($data)->toHaveKeys(['clientAccountCode', 'spendType', 'deploymentAmount', 'paymentRange', 'supplier']);

    expect($data['clientAccountCode'])->toBe(['type' => 'CLIENTID', 'value' => '1234'])
        ->and($data['spendType'])->toBe('Air')
        ->and($data['deploymentAmount'])->toBe(['value' => 150.0, 'currency' => 'GBP'])
        ->and($data['supplier'])->toBe(['name' => 'My Supplier'])
        ->and($data['consumerReference'])->toBe('pi_123')
        ->and($data['customer'])->toBe(['firstName' => 'John', 'lastName' => 'Doe']);

    expect($data['paymentRange'])->toHaveKeys(['startDate', 'endDate'])
        ->and(strtotime($data['paymentRange']['startDate']))->toBeLessThanOrEqual(strtotime($data['paymentRange']['endDate']));
});

it('falls back to Generic when purchase type is unknown enum value', function () {
    $request = makeIssueRequest(['spendCategory' => 'unknown']);

    expect($request->getData()['spendType'])->toBe('Generic');
});

it('accepts an already-mapped Conferma spend type string', function () {
    $request = makeIssueRequest(['spendCategory' => CardSpendCategory::TravelLodging->value]);

    expect($request->getData()['spendType'])->toBe('Accommodation');
});

it('omits customer when first/last name absent', function () {
    $request = makeIssueRequest(['firstName' => null, 'lastName' => null]);

    expect($request->getData())->not->toHaveKey('customer');
});

it('uses transactionReference as consumerReference when present', function () {
    $data = makeIssueRequest(['transactionReference' => 'pi_42'])->getData();

    expect($data['consumerReference'])->toBe('pi_42');
});

it('falls back to clientUniqueId for consumerReference when no transactionReference', function () {
    $data = makeIssueRequest(['transactionReference' => null, 'clientUniqueId' => 'idem-1'])->getData();

    expect($data['consumerReference'])->toBe('idem-1');
});

it('rejects requests without a supplier name', function () {
    $request = makeIssueRequest(['defaultSupplierName' => null]);

    expect(fn () => $request->getData())->toThrow(InvalidArgumentException::class);
});

it('accepts a per-call supplierName override', function () {
    $request = makeIssueRequest(['supplierName' => 'Hotel Acme']);

    expect($request->getData()['supplier'])->toBe(['name' => 'Hotel Acme']);
});

it('sends the deployment payload to /deployments/v1/deployments and parses success', function () {
    $client = Mockery::mock(ConfermaHttpClientInterface::class);
    $client->shouldReceive('post')
        ->once()
        ->with('/deployments/v1/deployments', Mockery::on(fn ($body) => $body['spendType'] === 'Accommodation'))
        ->andReturn([
            'deploymentId' => 'dep_42',
            'consumerReference' => 'pi_42',
            'status' => 'Deployed',
            'cardDetails' => [
                'number' => '4111111111111111',
                'cvv' => '123',
                'expiryYear' => 2027,
                'expiryMonth' => 12,
            ],
        ]);

    $request = makeIssueRequest([
        'confermaClient' => $client,
        'spendCategory' => CardSpendCategory::TravelLodging->value,
    ]);
    $response = $request->send();

    expect($response)->toBeInstanceOf(IssueVirtualCardResponse::class)
        ->and($response->isSuccessful())->toBeTrue()
        ->and($response->getTransactionReference())->toBe('dep_42');

    $result = $response->toVirtualCardResult();
    expect($result->success)->toBeTrue()
        ->and($result->cardGuid)->toBe('dep_42')
        ->and($result->cardNumber)->toBe('4111111111111111')
        ->and($result->cvv)->toBe('123')
        ->and($result->expirationDate)->toBe('12/27')
        ->and($result->status)->toBe('Deployed');
});
