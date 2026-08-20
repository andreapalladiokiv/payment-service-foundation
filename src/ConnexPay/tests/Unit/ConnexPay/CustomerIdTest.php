<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Techork\PaymentService\ConnexPay\CaptureRequest;
use Techork\PaymentService\ConnexPay\RefundRequest;
use Techork\PaymentService\ConnexPay\VoidRequest;

/**
 * ConnexPay documents `CustomerID` as "a secondary identifier in conjunction with OrderNumber",
 * searchable in its portal. It was sent nowhere, because there was no customer id to send: the
 * repository injected into every ConnexPay request is read by none, since ConnexPay has no
 * customer object of its own to reference.
 *
 * It is accepted on **Auth Only, Create Sale and Capture** and absent from **Void** and
 * **Return**, so it cannot ride `withIdentifiers()` with the other two.
 */
const CXP_CUSTOMER_ID = '0199f0a2-1c3a-7b8d-9e4f-aabbccddeeff';

function connexPayCaptureWithCustomer(): CaptureRequest
{
    $request = new CaptureRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(5000, new Currency('USD')),
        'transactionReference' => 'auth-guid-abc',
        'deviceGuid' => 'device-123',
        'clientUniqueId' => '0199f0a2-1c3a-7b8d-9e4f-000000000001:capture',
        'customerId' => CXP_CUSTOMER_ID,
    ]);

    return $request;
}

it('sends the customer id on capture, which accepts it', function () {
    expect(connexPayCaptureWithCustomer()->getData()['CustomerID'])->toBe(CXP_CUSTOMER_ID);
});

it('omits the field when the caller named no customer', function () {
    $request = new CaptureRequest(new OmnipayClient, new HttpRequest);
    $request->initialize(['transactionReference' => 'auth-guid-abc', 'deviceGuid' => 'device-123']);

    expect($request->getData())->not->toHaveKey('CustomerID');
});

/**
 * Void and Return do not list the field. Sending one anyway is the kind of guess that produced
 * `getChallenge()` reading fields ConnexPay never returned.
 */
it('sends it on nothing that does not accept it', function (callable $make) {
    expect($make()->getData())->not->toHaveKey('CustomerID');
})->with([
    'void' => [function () {
        $request = new VoidRequest(new OmnipayClient, new HttpRequest);
        $request->initialize([
            'transactionReference' => 'sale-guid', 'deviceGuid' => 'device-123',
            'clientUniqueId' => '0199f0a2-1c3a-7b8d-9e4f-000000000001:cancel',
            'customerId' => CXP_CUSTOMER_ID,
        ]);

        return $request;
    }],
    'return' => [function () {
        $request = new RefundRequest(new OmnipayClient, new HttpRequest);
        $request->initialize([
            'money' => new Money(500, new Currency('USD')),
            'transactionReference' => 'sale-guid', 'deviceGuid' => 'device-123',
            'clientUniqueId' => '0199f0a2-1c3a-7b8d-9e4f-000000000001',
            'customerId' => CXP_CUSTOMER_ID,
        ]);

        return $request;
    }],
]);
