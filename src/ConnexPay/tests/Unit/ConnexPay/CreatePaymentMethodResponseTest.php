<?php

declare(strict_types=1);

use Omnipay\Common\Message\RequestInterface;
use Techork\PaymentService\ConnexPay\CreatePaymentMethodResponse;

/**
 * In ConnexPay the same card guid serves as both token and payment method
 * reference, so CreatePaymentMethodRequest::sendData synthesizes the
 * response. These tests guard the synthesized shape.
 */
it('exposes the synthesized guid as transaction reference', function () {
    $response = new CreatePaymentMethodResponse(Mockery::mock(RequestInterface::class), [
        'wasProcessed' => true,
        'guid' => 'card-guid-abc',
    ]);

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->getTransactionReference())->toBe('card-guid-abc');
});
