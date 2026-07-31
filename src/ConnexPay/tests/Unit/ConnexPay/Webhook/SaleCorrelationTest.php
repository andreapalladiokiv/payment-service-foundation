<?php

declare(strict_types=1);

use Techork\PaymentService\ConnexPay\Webhook\SaleCorrelation;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;

const CORRELATION_PI_ID = '01991234-0000-7000-8000-aabbccddeeff';

function correlationResolver(?string $returns, ?string $expectedReference = null): TransactionIdResolver
{
    $resolver = Mockery::mock(TransactionIdResolver::class);
    $expectation = $resolver->shouldReceive('resolvePaymentIntent');

    if ($expectedReference !== null) {
        $expectation->with(Mockery::any(), $expectedReference);
    }

    $expectation->andReturn($returns);

    return $resolver;
}

it('resolves a card-data sale by its stored guid', function () {
    $correlation = SaleCorrelation::resolve(
        correlationResolver(CORRELATION_PI_ID, 'sale-guid-1'),
        GatewayId::generate(),
        'sale-guid-1',
        ['guid' => 'sale-guid-1', 'orderNumber' => 'ignored-because-guid-won'],
    );

    expect($correlation->found())->toBeTrue()
        ->and($correlation->paymentIntentId)->toBe(CORRELATION_PI_ID)
        ->and($correlation->viaOrderNumber)->toBeFalse();
});

it('falls back to orderNumber for a hosted sale we never stored a guid for', function () {
    $correlation = SaleCorrelation::resolve(
        correlationResolver(null),
        GatewayId::generate(),
        'guid-we-have-never-seen',
        ['orderNumber' => CORRELATION_PI_ID],
    );

    expect($correlation->found())->toBeTrue()
        ->and($correlation->paymentIntentId)->toBe(CORRELATION_PI_ID)
        // The flag is the signal that this sale was created on ConnexPay's page
        // and has never been confirmed on our side.
        ->and($correlation->viaOrderNumber)->toBeTrue();
});

it('accepts the PascalCase spelling of orderNumber', function () {
    $correlation = SaleCorrelation::resolve(
        correlationResolver(null),
        GatewayId::generate(),
        'unknown',
        ['OrderNumber' => CORRELATION_PI_ID],
    );

    expect($correlation->paymentIntentId)->toBe(CORRELATION_PI_ID);
});

it('strips the operation suffix the outbound side appends', function (string $suffix) {
    $correlation = SaleCorrelation::resolve(
        correlationResolver(null),
        GatewayId::generate(),
        'unknown',
        ['orderNumber' => CORRELATION_PI_ID.$suffix],
    );

    expect($correlation->paymentIntentId)->toBe(CORRELATION_PI_ID);
})->with([':capture', ':cancel']);

it('refuses an orderNumber that is not shaped like one of our aggregate ids', function (mixed $orderNumber) {
    $correlation = SaleCorrelation::resolve(
        correlationResolver(null),
        GatewayId::generate(),
        'unknown',
        ['orderNumber' => $orderNumber],
    );

    expect($correlation->found())->toBeFalse()
        ->and($correlation->paymentIntentId)->toBeNull();
})->with([
    'merchant-invoice-4711',
    '',
    '../../etc/passwd',
    '01991234-0000-7000-8000-aabbccddeeff-and-more',
    'not-a-uuid-at-all-just-long-enough-to-look-like-one',
]);

it('refuses a payload with no orderNumber at all', function () {
    $correlation = SaleCorrelation::resolve(
        correlationResolver(null),
        GatewayId::generate(),
        'unknown',
        ['guid' => 'unknown'],
    );

    expect($correlation->found())->toBeFalse();
});

it('does not consult the resolver when the sale carries no guid', function () {
    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldNotReceive('resolvePaymentIntent');

    $correlation = SaleCorrelation::resolve(
        $resolver,
        GatewayId::generate(),
        '',
        ['orderNumber' => CORRELATION_PI_ID],
    );

    expect($correlation->paymentIntentId)->toBe(CORRELATION_PI_ID);
});
