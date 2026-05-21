<?php

declare(strict_types=1);

use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Techork\PaymentService\ConnexPay\CaptureRequest;

it('builds capture data with DeviceGuid and AuthOnlyGuid', function () {
    $request = new CaptureRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new \Money\Money(5000, new \Money\Currency('USD')),
        'transactionReference' => 'auth-guid-abc',
        'deviceGuid' => 'device-123',
    ]);

    $data = $request->getData();

    expect($data['DeviceGuid'])->toBe('device-123')
        ->and($data['AuthOnlyGuid'])->toBe('auth-guid-abc')
        ->and($data['ConnexPayTransaction']['ExpectedPayments'])->toBe(1)
        ->and($data)->not->toHaveKey('OrderNumber');
});

it('includes OrderNumber when clientUniqueId is set', function () {
    $request = new CaptureRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'transactionReference' => 'auth-guid-abc',
        'deviceGuid' => 'device-123',
        'clientUniqueId' => 'pi-uuid-7:capture',
    ]);

    expect($request->getData()['OrderNumber'])->toBe('pi-uuid-7:capture');
});
