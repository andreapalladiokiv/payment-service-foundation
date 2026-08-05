<?php

declare(strict_types=1);

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Techork\PaymentService\ConnexPay\Webhook\HttpServiceFeeFetcher;
use Techork\PaymentService\Gateway\Contract\GatewayCredentialRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Covers the fee extraction, which is where this class does arithmetic on money.
 *
 * Reached through reflection, deliberately and with a caveat: `fetchSaleFee()` and
 * `fetchPurchaseFee()` build their HTTP client with `new ConnexPayClient(...)` inline, so
 * there is no seam to substitute. Even the failure branch would have to attempt a real
 * connection. That leaves 64 statements uncoverable as written, and `extractFee` — the only
 * part that decides an amount — private behind them.
 *
 * So this pins the arithmetic and the defensive key lookup, and the seam is worth adding if
 * the fetch paths are ever to be tested: taking a client (or a factory) as a constructor
 * dependency would make both public methods reachable without a network.
 *
 * Why it is worth pinning at all: the class turns a decimal quoted by ConnexPay into minor
 * units, and the field it reads is undocumented — its own docblock says the shape was never
 * published, so it tries three spellings and warns when none match. Both of those are easy
 * to "tidy" into something that silently reports a different number.
 */
function connexPayFeeExtractor(?LoggerInterface $logger = null): callable
{
    $fetcher = new HttpServiceFeeFetcher(
        Mockery::mock(GatewayCredentialRepository::class),
        $logger ?? new class extends AbstractLogger
        {
            public function log($level, string|Stringable $message, array $context = []): void {}
        },
    );

    $method = new ReflectionMethod($fetcher, 'extractFee');

    /**
     * @param  array<string, mixed>  $row
     */
    return static fn (array $row): ?\Money\Money => $method->invoke(
        $fetcher,
        $row,
        'sale',
        'sale-guid-1',
        GatewayId::generate(),
    );
}

function connexPayRecordingLogger(): LoggerInterface
{
    return new class extends AbstractLogger
    {
        /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
        public array $records = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
        }
    };
}

it('reads the fee as a major-currency decimal and returns minor units', function () {
    // ConnexPay quotes these as decimals, matching how IssueVirtualCard sends them. Getting
    // this wrong by a factor of a hundred is the whole risk in the method.
    expect(connexPayFeeExtractor()(['serviceFee' => '1.23'])?->getAmount())->toBe('123')
        ->and(connexPayFeeExtractor()(['serviceFee' => 1.23])?->getAmount())->toBe('123')
        ->and(connexPayFeeExtractor()(['serviceFee' => 12])?->getAmount())->toBe('1200');
});

it('accepts each spelling the undocumented payload might use', function (string $key) {
    // The class tries three because the field shape was never published. A "cleanup" down to
    // one would read as tidying and would start returning null on live payloads.
    expect(connexPayFeeExtractor()([$key => '2.50'])?->getAmount())->toBe('250');
})->with([
    'documented' => ['serviceFee'],
    'pascal case' => ['ServiceFee'],
    'short' => ['fee'],
]);

it('prefers the documented spelling when more than one is present', function () {
    expect(connexPayFeeExtractor()(['serviceFee' => '1.00', 'ServiceFee' => '9.00', 'fee' => '8.00'])?->getAmount())
        ->toBe('100');
});

it('reports no fee and says so in the log when no spelling matches', function () {
    // Null means "no fee available right now", which the caller turns into Skipped so the
    // delivery layer retries. The warning is the operator's only signal that the field name
    // needs verifying against a sandbox sample, so it carries the keys that WERE present.
    $logger = connexPayRecordingLogger();

    expect(connexPayFeeExtractor($logger)(['amount' => '10.00', 'guid' => 'abc']))->toBeNull()
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['message'])->toContain('serviceFee absent')
        ->and($logger->records[0]['context']['available_keys'])->toBe(['amount', 'guid']);
});

it('reports no fee for an amount that is not a positive one', function (mixed $raw) {
    // A zero fee is not a fee, and Money would happily hold it — the row would then record a
    // fee event stating nothing was charged.
    expect(connexPayFeeExtractor()(['serviceFee' => $raw]))->toBeNull();
})->with([
    'zero' => ['0'],
    'zero decimal' => ['0.00'],
    'negative' => ['-1.50'],
    'below half a cent' => ['0.004'],
    'not a number' => ['abc'],
]);

it('takes the currency from the row and normalises its case', function (string $key) {
    expect(connexPayFeeExtractor()(['serviceFee' => '1.00', $key => 'gbp'])?->getCurrency()->getCode())
        ->toBe('GBP');
})->with([
    'lower case key' => ['currency'],
    'pascal case key' => ['Currency'],
]);

it('falls back to USD only when the row names no currency at all', function () {
    expect(connexPayFeeExtractor()(['serviceFee' => '1.00'])?->getCurrency()->getCode())->toBe('USD');
});

it('reports no fee when the row names an empty currency', function () {
    // The `?? 'USD'` default covers an absent key, not a present-but-empty one, and a fee in
    // no currency cannot be recorded against a payment.
    expect(connexPayFeeExtractor()(['serviceFee' => '1.00', 'currency' => '']))->toBeNull();
});
