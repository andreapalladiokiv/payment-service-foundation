<?php

declare(strict_types=1);

use Techork\PaymentService\Common\Contract\EncryptInterface;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Recorder\NoOpGatewayPaymentMethodRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;

it('skips every payment method record', function () {
    $enc = new class implements EncryptInterface
    {
        public function encrypt(string $d): string
        {
            return $d;
        }
    };

    $outcome = new NoOpGatewayPaymentMethodRecorder()->onPaymentMethodRecord(
        gatewayId: GatewayId::generate(),
        customerReference: 'cus_123',
        paymentMethodReference: 'pm_123',
        creditCard: new CreditCard(
            Number::fromNumber('4242424242424242', $enc),
            Expiration::fromMonthAndYear(12, 2030),
            new Holder('Test'),
            Cvc::fromCvc('123', $enc),
        ),
        billingAddress: new BillingAddress('Test', 'User', '1 St', 'NYC', new Country('US'), '10001'),
    );

    expect($outcome)->toBe(RecorderOutcome::Skipped);
});
