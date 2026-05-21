<?php

declare(strict_types=1);

use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Techork\PaymentService\ConnexPay\VoidRequest;

it('builds void data with DeviceGuid and AuthOnlyGuid', function () {
    $request = new VoidRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'transactionReference' => 'auth-guid-xyz',
        'deviceGuid' => 'device-789',
    ]);

    $data = $request->getData();

    expect($data['DeviceGuid'])->toBe('device-789')
        ->and($data['AuthOnlyGuid'])->toBe('auth-guid-xyz')
        ->and($data)->not->toHaveKey('OrderNumber');
});

it('includes OrderNumber when clientUniqueId is set', function () {
    $request = new VoidRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'transactionReference' => 'auth-guid-xyz',
        'deviceGuid' => 'device-789',
        'clientUniqueId' => 'pi-uuid-7:cancel',
    ]);

    expect($request->getData()['OrderNumber'])->toBe('pi-uuid-7:cancel');
});
