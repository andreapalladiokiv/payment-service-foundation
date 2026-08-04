<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\PaymentInitiation;

it('treats CardholderInitiated as a cardholder-initiated (CIT) transaction', function () {
    expect(PaymentInitiation::CardholderInitiated->isCardholderInitiated())->toBeTrue()
        ->and(PaymentInitiation::CardholderInitiated->isMerchantInitiated())->toBeFalse();
});

it('treats the merchant variants as merchant-initiated (MIT)', function () {
    expect(PaymentInitiation::MerchantRecurring->isMerchantInitiated())->toBeTrue()
        ->and(PaymentInitiation::MerchantUnscheduled->isMerchantInitiated())->toBeTrue()
        ->and(PaymentInitiation::MerchantRecurring->isCardholderInitiated())->toBeFalse()
        ->and(PaymentInitiation::MerchantUnscheduled->isCardholderInitiated())->toBeFalse();
});
