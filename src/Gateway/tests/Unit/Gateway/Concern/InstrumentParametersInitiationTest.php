<?php

declare(strict_types=1);

use Omnipay\Common\Http\ClientInterface;
use Omnipay\Common\Message\AbstractRequest;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Techork\PaymentService\Common\ValueObject\PaymentInitiation;
use Techork\PaymentService\Gateway\Concern\InstrumentParameters;

/**
 * The router hands adapters a parameter ARRAY, which Omnipay binds through
 * `Helper::initialize` — and that helper silently ignores any key with no
 * matching setter. So "the router puts `initiation` in the array" and "the
 * adapter can read it" are two different facts, and the gap between them fails
 * without a word: every payment would simply look cardholder-initiated.
 */
function initiationRequest(): AbstractRequest
{
    return new class(Mockery::mock(ClientInterface::class), new HttpRequest) extends AbstractRequest
    {
        use InstrumentParameters;

        public function getData(): array
        {
            return [];
        }

        public function sendData($data): never
        {
            throw new LogicException('This request exists to hold parameters, not to be sent.');
        }
    };
}

it('binds the initiation key from an Omnipay parameter array', function (PaymentInitiation $initiation) {
    $request = initiationRequest();
    $request->initialize(['initiation' => $initiation]);

    expect($request->getInitiation())->toBe($initiation);
})->with([
    PaymentInitiation::CardholderInitiated,
    PaymentInitiation::MerchantRecurring,
    PaymentInitiation::MerchantUnscheduled,
]);

it('reads back what the setter was given', function () {
    $request = initiationRequest();
    $request->setInitiation(PaymentInitiation::MerchantUnscheduled);

    expect($request->getInitiation())->toBe(PaymentInitiation::MerchantUnscheduled);
});

/**
 * The default is the load-bearing part, and it points one way on purpose. An
 * adapter reading an unset initiation gets "cardholder present", which is the
 * conservative answer: it claims no exemption and asks for no MIT treatment. The
 * opposite default would have every adapter that forgets to set it declaring an
 * SCA exemption nobody is entitled to.
 */
it('defaults to cardholder-initiated rather than returning null', function () {
    expect(initiationRequest()->getInitiation())->toBe(PaymentInitiation::CardholderInitiated);
});
