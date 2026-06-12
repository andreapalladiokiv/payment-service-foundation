<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\ConnexPay\IssueVirtualCardRequest;

function makeConnexPayIssueRequest(array $overrides = []): IssueVirtualCardRequest
{
    $request = new IssueVirtualCardRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(5000, new Currency('USD')),
        'merchantGuid' => '5e2a814b-accc-4473-8d02-44b807200336',
        'incomingTransactionCode' => '5406E46639136547414675607',
        'spendCategory' => 'travel_air',
        'firstName' => 'Test',
        'lastName' => 'User',
        ...$overrides,
    ]);

    return $request;
}

it('omits CardBrand when none is requested', function () {
    expect(makeConnexPayIssueRequest()->getData())->not->toHaveKey('CardBrand');
});

it('translates lowercase visa to PascalCase Visa', function () {
    $data = makeConnexPayIssueRequest(['cardBrand' => CardBrand::Visa])->getData();

    expect($data['CardBrand'])->toBe('Visa');
});

it('translates lowercase mastercard to PascalCase Mastercard', function () {
    $data = makeConnexPayIssueRequest(['cardBrand' => CardBrand::Mastercard])->getData();

    expect($data['CardBrand'])->toBe('Mastercard');
});

it('throws on unsupported brand', function () {
    makeConnexPayIssueRequest(['cardBrand' => CardBrand::Amex])->getData();
})->throws(InvalidArgumentException::class, 'Unsupported ConnexPay card brand: amex');

it('forwards clientUniqueId as OrderNumber and omits it when absent', function () {
    expect(makeConnexPayIssueRequest(['clientUniqueId' => 'ORD-42'])->getData()['OrderNumber'])->toBe('ORD-42')
        ->and(makeConnexPayIssueRequest()->getData())->not->toHaveKey('OrderNumber');
});

it('builds the full request body with required fields', function () {
    $data = makeConnexPayIssueRequest(['cardBrand' => CardBrand::Visa])->getData();

    expect($data['MerchantGuid'])->toBe('5e2a814b-accc-4473-8d02-44b807200336')
        ->and($data['AmountLimit'])->toBe(50.0)
        ->and($data['FirstName'])->toBe('Test')
        ->and($data['LastName'])->toBe('User')
        ->and($data['PurchaseType'])->toBe('01')
        ->and($data['IncomingTransactionCode'])->toBe('5406E46639136547414675607')
        ->and($data['ReturnCardData'])->toBeTrue()
        ->and($data['CardBrand'])->toBe('Visa');
});
