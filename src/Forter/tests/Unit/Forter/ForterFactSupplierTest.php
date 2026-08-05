<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\ConnectionContext;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CreditCard\CardSummary;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\IpAddress;
use Techork\PaymentService\Forter\FraudDecision;
use Techork\PaymentService\Forter\FraudScreeningProvider;
use Techork\PaymentService\Forter\FraudScreeningRequest;
use Techork\PaymentService\Forter\FraudVerdict;
use Techork\PaymentService\Forter\ForterFactSupplier;

function factSupplierRequest(): FraudScreeningRequest
{
    return new FraudScreeningRequest(
        reference: 'ref-1',
        card: new CardSummary('411111', '1111', CardBrand::Visa, Expiration::fromMonthAndYear(1, 2031), new Holder('A B')),
        billing: new BillingAddress('Ada', 'Lovelace', '1 Main St', 'London', new Country('GB'), 'E1 6AN'),
        amountMinorUnits: 12345,
        currencyCode: 'USD',
        connection: new ConnectionContext(new IpAddress('203.0.113.7'), 'Mozilla/5.0'),
    );
}

/**
 * @param FraudVerdict|Throwable $answer
 * @return FraudScreeningProvider
 */
function screeningProviderReturning(FraudVerdict|Throwable $answer): FraudScreeningProvider
{
    return new class($answer) implements FraudScreeningProvider
    {
        public int $calls = 0;

        public function __construct(private readonly FraudVerdict|Throwable $answer) {}

        public function screen(FraudScreeningRequest $request): FraudVerdict
        {
            $this->calls++;

            if ($this->answer instanceof Throwable) {
                throw $this->answer;
            }

            return $this->answer;
        }
    };
}

it('exposes the verdict as facts a rule can match on', function () {
    $supplier = new ForterFactSupplier(
        screeningProviderReturning(new FraudVerdict(FraudDecision::Decline, 'velocity', 'forter-ref')),
        factSupplierRequest(),
    );

    expect($supplier->facts())->toBe([
        'screening' => [
            'decision' => 'decline',
            'reason_code' => 'velocity',
            'reference' => 'forter-ref',
            'is_approved' => false,
            'is_declined' => true,
            'is_inconclusive' => false,
        ],
    ]);
});

it('marks a not-reviewed screening inconclusive, so a rule can decide what that costs', function () {
    $supplier = new ForterFactSupplier(
        screeningProviderReturning(new FraudVerdict(FraudDecision::NotReviewed)),
        factSupplierRequest(),
    );

    $screening = $supplier->facts()['screening'];

    expect($screening['is_inconclusive'])->toBeTrue()
        ->and($screening['is_approved'])->toBeFalse()
        ->and($screening['is_declined'])->toBeFalse();
});

it('screens once however many times it is asked, because it is a network call', function () {
    $provider = screeningProviderReturning(new FraudVerdict(FraudDecision::Approve));
    $supplier = new ForterFactSupplier($provider, factSupplierRequest());

    $supplier->facts();
    $supplier->facts();
    $supplier->verdict();

    expect($provider->calls)->toBe(1);
});

it('turns provider unavailability into missing signals, not a failed assessment', function () {
    $supplier = new ForterFactSupplier(
        screeningProviderReturning(new RuntimeException('forter is down')),
        factSupplierRequest(),
    );

    $screening = $supplier->facts()['screening'];

    expect($screening['decision'])->toBeNull()
        ->and($screening['is_approved'])->toBeFalse()
        ->and($screening['is_declined'])->toBeFalse()
        ->and($screening['is_inconclusive'])->toBeFalse()
        ->and($supplier->verdict())->toBeNull();
});

it('keeps the verdict object for callers that must persist or forward it', function () {
    $verdict = new FraudVerdict(FraudDecision::Approve, null, 'forter-ref');
    $supplier = new ForterFactSupplier(screeningProviderReturning($verdict), factSupplierRequest());

    expect($supplier->verdict())->toBe($verdict);
});
