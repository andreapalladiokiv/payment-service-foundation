<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Techork\PaymentService\ConnexPay\RefundRequest;

it('builds refund data with DeviceGuid, SaleGuid and Amount', function () {
    $request = new RefundRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(2500, new Currency('USD')),
        'transactionReference' => 'sale-guid-xyz',
        'deviceGuid' => 'device-456',
    ]);

    $data = $request->getData();

    expect($data['DeviceGuid'])->toBe('device-456')
        ->and($data['SaleGuid'])->toBe('sale-guid-xyz')
        ->and($data['Amount'])->toBe(25.00)
        ->and($data)->not->toHaveKey('OrderNumber');
});

it('includes OrderNumber when clientUniqueId is set', function () {
    $request = new RefundRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(2500, new Currency('USD')),
        'transactionReference' => 'sale-guid-xyz',
        'deviceGuid' => 'device-456',
        'clientUniqueId' => 'refund-uuid-9',
    ]);

    expect($request->getData()['OrderNumber'])->toBe('refund-uuid-9');
});
