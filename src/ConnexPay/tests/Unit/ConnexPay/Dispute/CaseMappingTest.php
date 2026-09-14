<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\ConnexPay\Dispute\CaseMapping;
use Techork\PaymentService\ConnexPay\Dispute\UnrepresentableCase;

/**
 * The provider-code tables F5 and F9 both read, pinned row by row.
 *
 * These are the plan's own tables and, for the status, our reading of ConnexPay's field
 * definitions — see {@see CaseMapping::status()}, which says what is quoted and what is inferred.
 * The tests below assert the reading as it stands so that a change to it is a visible change to a
 * test rather than a silent change to how a dispute is decided.
 *
 * Helpers here are prefixed `cmsMapping…`; Pest helpers are global for the whole suite.
 */
it('maps every CaseType the reference table lists onto the stage the reference names', function (int|string $caseType, string $stage) {
    expect(CaseMapping::stage($caseType))->toBe($stage);
})->with([
    // The table in the plan's Provider Reference, verbatim: left column is ConnexPay's `CaseType`,
    // right column the stage the plan names for it.
    '1 first chargeback' => [1, 'chargeback'],
    '2 second chargeback' => [2, 'chargeback'],
    '3 first reversal' => [3, 'chargeback'],
    '4 second reversal' => [4, 'chargeback'],
    '9 visa pre-arb' => [9, 'pre_arbitration'],
    '17 amex retrieval' => [17, 'inquiry'],
    '18 amex chargeback' => [18, 'chargeback'],
    '24 collaboration pre-arb' => [24, 'pre_arbitration'],
    // The same code as a string, because ConnexPay's documented sample quotes `CaseType` and a
    // re-serialised payload does not — the two must not map differently.
    '2 as a string' => ['2', 'chargeback'],
    '17 as a string' => ['17', 'inquiry'],
]);

it('gives no stage to a CaseType the table does not cover', function (int|string|null $caseType) {
    // ConnexPay's published table is wider than the plan's, and its own sample returns 25. A
    // default of `chargeback` here would put a retrieval or an arbitration on the wrong branch of
    // the aggregate and hand the project another phase's deadline and evidence set.
    expect(CaseMapping::stage($caseType))->toBeNull();
})->with([
    '25, in ConnexPay\'s own sample' => [25],
    '0 retrieval' => [0],
    '11 discover phase' => [11],
    '20' => [20],
    '28' => [28],
    'an empty string' => [''],
    'null' => [null],
    'not a number at all' => ['first'],
]);

it('says the merchant is waiting only for ResolutionTo M', function (?string $resolutionTo, bool $onMerchant, bool $onBank) {
    expect(CaseMapping::waitsOnMerchant($resolutionTo))->toBe($onMerchant)
        ->and(CaseMapping::waitsOnBank($resolutionTo))->toBe($onBank);
})->with([
    // M and B are ConnexPay's own definitions ("indicates the party responsible for responding").
    'M, merchant responds' => ['M', true, false],
    'B, bank responds' => ['B', false, true],
    // S and G resolve the case without anyone responding, so neither is waiting on us and neither
    // is waiting on the bank — which is why there are two predicates and not one.
    'S, split' => ['S', false, false],
    'G, general ledger' => ['G', false, false],
    'absent' => [null, false, false],
    'lowercase m is not M' => ['m', false, false],
    'an empty string' => ['', false, false],
]);

it('reads a documented WinLoss as a decided case before it reads who is waiting', function (?string $resolutionTo, ?string $winLoss, ?string $status) {
    expect(CaseMapping::status($resolutionTo, $winLoss))->toBe($status);
})->with([
    // ConnexPay documents WinLoss as exactly three values and defines the first two as statements
    // about the bank account, which is what `won` / `lost` mean on the aggregate.
    'Win is won, whoever was waiting' => ['B', 'Win', 'won'],
    'Loss is lost, whoever was waiting' => ['M', 'Loss', 'lost'],
    'a decided case wins over the waiting party' => ['M', 'Win', 'won'],
    // `Loss (Pending)` is deliberately NOT lost: ConnexPay defines it as a negative net position
    // whose status is "Not Worked", i.e. a case still open for us to answer. It falls through to
    // the ResolutionTo rule, which is our reading rather than a quotation.
    'Loss (Pending) with M is still to be worked' => ['M', 'Loss (Pending)', 'needs_response'],
    'Loss (Pending) with B is with the bank' => ['B', 'Loss (Pending)', 'under_review'],
    'M is needs_response' => ['M', null, 'needs_response'],
    'B is under_review' => ['B', null, 'under_review'],
    // S and G resolve the case with no counterpart in DisputeStatus, and neither is a win or a
    // loss. Null is the honest answer rather than squeezing them into `under_review`.
    'S produces no status' => ['S', null, null],
    'G produces no status' => ['G', null, null],
    'nothing stated at all' => [null, null, null],
    // An unrecognised WinLoss is never read as a decision, and never invents one by falling back
    // to something it cannot justify.
    'an unknown WinLoss is not a decision' => ['M', 'Maybe', 'needs_response'],
    'an unknown WinLoss with no waiter' => ['S', 'Maybe', null],
    'the spelling has to be exact' => [null, 'win', null],
]);

it('maps CardBrand 1-4 onto the common vocabulary and refuses 5', function (int|string $cardBrand, CardBrand $expected) {
    expect(CaseMapping::cardBrand($cardBrand))->toBe($expected);
})->with([
    '1 visa' => [1, CardBrand::Visa],
    '2 mastercard' => [2, CardBrand::Mastercard],
    '3 discover' => [3, CardBrand::Discover],
    '4 amex' => [4, CardBrand::Amex],
    '4 as a string' => ['4', CardBrand::Amex],
]);

it('refuses CardBrand 5 and anything outside 1-4 instead of guessing one', function (int|string|null $cardBrand) {
    // 5 is PayPal, which has no case in Common\ValueObject\CardBrand. The reference says to map it
    // to a typed refusal rather than a guess, and this is that refusal.
    expect(fn () => CaseMapping::cardBrand($cardBrand))->toThrow(UnrepresentableCase::class);
})->with([
    '5 paypal' => [5],
    '0' => [0],
    '6' => [6],
    'absent' => [null],
    'not a number' => ['visa'],
]);

it('names the refused brand so the operator can see which one it was', function () {
    // A refusal an operator cannot look up is a refusal they cannot act on, so the value travels
    // in the message.
    expect(fn () => CaseMapping::cardBrand(5))->toThrow(UnrepresentableCase::class, '5');
});
