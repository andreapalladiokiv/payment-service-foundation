<?php

declare(strict_types=1);

use Money\Money;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\ErrorCode;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ECICode;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSStatus;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSVersion;
use Techork\PaymentService\Domain\PaymentIntent\CaptureMethod;
use Techork\PaymentService\Domain\PaymentIntent\Event\PaymentIntentFailed;
use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentAggregate;
use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentStatus;
use Techork\PaymentService\Domain\PaymentIntent\Port\ChallengeOutcome;
use Techork\PaymentService\Domain\PaymentIntent\Port\ChallengePort;
use Techork\PaymentService\Domain\PaymentIntent\Port\FirewallDecision;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\VerifyChallengeRequest;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;
use Techork\PaymentService\Tests\Support\StubPaymentIntentFirewall;

/**
 * The whole 3DS arrangement, driven end to end without an HTTP layer.
 *
 * Everything else about this design is pinned one branch at a time. What this file asks is
 * different and worth asking separately: put the pieces in a line and does a real merchant
 * integration actually work — refused for want of authentication, authenticate out of band, send
 * it again, paid — and do the attacks the design depends on refusing actually get refused.
 *
 * The port below is the reason this is a test of the scheme rather than of the aggregate. A stub
 * that answers `passed()` proves the aggregate calls it; it proves nothing about whether
 * {@see VerifyChallengeRequest} carries enough for an implementation to do its job. So this one is
 * written the way a real one has to be: a ledger of authentications the service issued, keyed by
 * the directory server's transaction id, each recording the amount and the instrument it was
 * obtained for and whether it has been spent. If the request were missing something, this class
 * could not be written, and that is the finding the test exists to produce.
 */
final class IssuedAuthentications implements ChallengePort
{
    /** @var array<string, array{amount: Money, instrument: array<string, mixed>, spent: bool}> */
    private array $issued = [];

    /** How many times anything asked this port to weigh evidence. */
    public int $verifications = 0;

    /**
     * What the merchant's own authentication endpoint does after polling the MPI: record what was
     * obtained, for which payment method and which amount.
     */
    public function issue(ThreeDSResult $result, Money $amount, PaymentInstrument $instrument): ThreeDSResult
    {
        $this->issued[$result->dsTransactionId] = [
            'amount' => $amount,
            // By value, not by identity. A real implementation keys on the stored payment
            // method's id, since it never sees the same object twice — and a raw card, having no
            // id, is a case it cannot bind at all.
            'instrument' => $instrument->toPayload(),
            'spent' => false,
        ];

        return $result;
    }

    public function verify(VerifyChallengeRequest $request): ChallengeOutcome
    {
        $this->verifications++;

        $presented = $request->presented;

        if (! $presented instanceof ThreeDSResult) {
            return ChallengeOutcome::refused('not a 3DS authentication');
        }

        $record = $this->issued[$presented->dsTransactionId] ?? null;

        // Never issued by us. The whole point: a well-formed result is indistinguishable from an
        // invented one by looking at it, so it is looked up rather than read.
        if ($record === null) {
            return ChallengeOutcome::refused('no such authentication');
        }

        if ($record['spent']) {
            return ChallengeOutcome::refused('authentication already used');
        }

        if (! $record['amount']->equals($request->amount)) {
            return ChallengeOutcome::refused('authentication was obtained for a different amount');
        }

        if ($record['instrument'] !== $request->instrument->toPayload()) {
            return ChallengeOutcome::refused('authentication was obtained for a different instrument');
        }

        $this->issued[$presented->dsTransactionId]['spent'] = true;

        // The record's own copy, not the caller's. Here they are the same object; in a real
        // implementation the stored one is what the MPI said.
        return ChallengeOutcome::passed($presented);
    }
}

function threeDSAuthentication(string $dsTransactionId = '11111111-1111-1111-1111-111111111111'): ThreeDSResult
{
    return new ThreeDSResult(
        ThreeDSStatus::Successful,
        'cavv-base64',
        ECICode::VisaSuccessful,
        $dsTransactionId,
        '22222222-2222-2222-2222-222222222222',
        ThreeDSVersion::V220,
    );
}

/**
 * @return array{0: PaymentIntentAggregate, 1: ?PaymentIntentFailed}
 */
function threeDSCreate(
    IssuedAuthentications $challenges,
    ?ThreeDSResult $presented = null,
    ?Money $amount = null,
    ?PaymentInstrument $instrument = null,
): array {
    $aggregate = PaymentIntentAggregate::create(
        makeCreatePiCommand(
            PaymentIntentId::generate(),
            CaptureMethod::Immediate,
            amount: $amount,
            instrument: $instrument,
            challengeResult: $presented,
        ),
        makePaySuccessPort(),
        StubPaymentIntentFirewall::returning(FirewallDecision::challenge('matched rule 9')),
        $challenges,
    );

    $failure = null;

    foreach ($aggregate->releaseEvents() as $event) {
        if ($event instanceof PaymentIntentFailed) {
            $failure = $event;
        }
    }

    return [$aggregate, $failure];
}

it('walks a merchant through the whole loop and takes the money', function () {
    // Attempt one: the chain wants a step-up, nothing was presented, and there is no cardholder
    // session here to conduct one in. Refused, terminally, with the code that says what to do.
    $challenges = new IssuedAuthentications;

    [$first, $failure] = threeDSCreate($challenges);

    expect($first->status())->toBe(PaymentIntentStatus::Failed)
        ->and($failure?->code)->toBe(ErrorCode::AuthenticationRequired)
        ->and($failure?->code->wasAttempted())->toBeTrue();

    // The merchant does the authentication out of band, against endpoints this service exposes,
    // and the service records what it issued.
    $result = $challenges->issue(threeDSAuthentication(), makeAmount(), makeInstrument());

    // Attempt two: same payment, now with evidence.
    [$second] = threeDSCreate($challenges, $result);

    expect($second->status())->toBe(PaymentIntentStatus::Charged)
        ->and($second->challengeResult())->toBe($result);
});

it('refuses an authentication it never issued', function () {
    // The attack the arrangement stands or falls on. Presenting a result is what carries a payment
    // past a step-up rule, and this one is perfectly well-formed — it passes every coherence check
    // there is, because coherence was never the question.
    [$aggregate, $failure] = threeDSCreate(new IssuedAuthentications, threeDSAuthentication());

    expect($aggregate->status())->toBe(PaymentIntentStatus::Failed)
        ->and($failure?->code)->toBe(ErrorCode::AuthenticationFailed);
});

it('refuses to spend the same authentication twice', function () {
    // Without this the first genuine authentication is a season ticket: replay it on every
    // subsequent payment and the step-up rule never applies again.
    $challenges = new IssuedAuthentications;
    $result = $challenges->issue(threeDSAuthentication(), makeAmount(), makeInstrument());

    [$paid] = threeDSCreate($challenges, $result);
    [$replayed, $failure] = threeDSCreate($challenges, $result);

    expect($paid->status())->toBe(PaymentIntentStatus::Charged)
        ->and($replayed->status())->toBe(PaymentIntentStatus::Failed)
        ->and($failure?->code)->toBe(ErrorCode::AuthenticationFailed);
});

it('refuses an authentication obtained for a different amount', function () {
    // A cardholder authenticating a small payment must not thereby authenticate a large one.
    $challenges = new IssuedAuthentications;
    $result = $challenges->issue(threeDSAuthentication(), Money::USD(1000), makeInstrument());

    [$aggregate, $failure] = threeDSCreate($challenges, $result, amount: Money::USD(500000));

    expect($aggregate->status())->toBe(PaymentIntentStatus::Failed)
        ->and($failure?->code)->toBe(ErrorCode::AuthenticationFailed);
});

it('refuses an authentication obtained for a different instrument', function () {
    // And a cardholder authenticating their own card must not thereby authenticate someone
    // else's, which is the version of this that matters.
    $challenges = new IssuedAuthentications;
    $result = $challenges->issue(threeDSAuthentication(), makeAmount(), makeInstrument());

    [$aggregate, $failure] = threeDSCreate($challenges, $result, instrument: makeCreditCardForPI());

    expect($aggregate->status())->toBe(PaymentIntentStatus::Failed)
        ->and($failure?->code)->toBe(ErrorCode::AuthenticationFailed);
});

it('tells the two refusals apart in a way a merchant can act on', function () {
    // The distinction the code exists for. Both attempts fail, and one means "do 3DS and send it
    // again" while the other means "that did not hold up, and repeating it will not help". A
    // merchant matching our prose would have to tell them apart by sentence.
    $challenges = new IssuedAuthentications;

    [, $missing] = threeDSCreate($challenges);
    [, $rejected] = threeDSCreate($challenges, threeDSAuthentication());

    expect($missing?->code)->toBe(ErrorCode::AuthenticationRequired)
        ->and($rejected?->code)->toBe(ErrorCode::AuthenticationFailed)
        ->and($missing?->reason)->toContain('matched rule 9');
});

// ─────────────────────────────────────────────────────────
//  The other 3DS path: a challenge the gateway raised itself
// ─────────────────────────────────────────────────────────

/**
 * Both halves of this were already pinned and the join was not. One test asserts that `create()`
 * records `PaymentIntentRequiresAction`; another starts by REPLAYING that event and asserts what
 * `confirmChallenge()` does with it. Nothing ran the two in sequence on one aggregate, so the
 * handover was proved by construction — the second test hand-writes the event the first produces,
 * and both use the same `makeThreeDSChallenge()`, which means a challenge mangled in between
 * would have been invisible to either.
 *
 * This is the path that still parks, and the only one that should: the gateway opened the payment
 * and handed back somewhere to send the cardholder, so there is something to present and a
 * pending interaction to wait on. No `ChallengePort` is involved — that port is for evidence a
 * merchant brings to a payment-intent call, and the two arrangements must not reach into each
 * other.
 */
function gatewayRaisedChallenge(): Techork\PaymentService\Common\ValueObject\Challenge\ThreeDSChallenge
{
    return new Techork\PaymentService\Common\ValueObject\Challenge\ThreeDSChallenge(
        authenticationId: 'b93c3892-1b22-41e9-bfa7-7d5337631112',
        url: 'https://acs.issuer.example/challenge',
        payload: 'creq=eyJ0aHJlZURTU2VydmVyVHJhbnNJRCI6ImI5M2MzODkyIn0',
    );
}

it('parks on the gateway challenge and finishes it on the same aggregate', function () {
    // create() through to confirmChallenge() without an event replay in between, which is the
    // part nothing covered.
    $raised = gatewayRaisedChallenge();
    $result = threeDSAuthentication();

    $aggregate = PaymentIntentAggregate::create(
        makeCreatePiCommand(PaymentIntentId::generate(), CaptureMethod::Immediate),
        makePayChallengePort($raised),
        StubPaymentIntentFirewall::allowing(),
    );

    expect($aggregate->status())->toBe(PaymentIntentStatus::RequiresAction);

    $aggregate->confirmChallenge($result, makeExternallyCompletedConfirmPort());

    expect($aggregate->status())->toBe(PaymentIntentStatus::Charged)
        ->and($aggregate->challengeResult())->toBe($result);
});

it('parks on the gateway challenge itself, field for field', function () {
    // What the client is sent has to be what the gateway said. Both existing tests used one
    // helper on both sides of the handover, so a challenge rebuilt or narrowed in the middle
    // would have compared equal to itself. This asserts the fields a client actually needs — an
    // address and what to post there — rather than object equality.
    $raised = gatewayRaisedChallenge();

    $parked = PaymentIntentAggregate::create(
        makeCreatePiCommand(PaymentIntentId::generate()),
        makePayChallengePort($raised),
        StubPaymentIntentFirewall::allowing(),
    )->challenge();

    expect($parked)->toBe($raised)
        ->and($parked?->transactionId())->toBe('b93c3892-1b22-41e9-bfa7-7d5337631112')
        ->and($parked->url)->toBe('https://acs.issuer.example/challenge')
        ->and($parked->payload)->toStartWith('creq=');
});

it('keeps the two challenge paths out of each other', function () {
    // The firewall allowed this payment, so nothing asked for a step-up; the gateway raised one
    // on its own. A ChallengePort is supplied and must go untouched — if the aggregate ever
    // routed a gateway-raised challenge through it, a merchant would be asked to verify evidence
    // for an authentication that has not happened yet.
    $challenges = new IssuedAuthentications;

    $aggregate = PaymentIntentAggregate::create(
        makeCreatePiCommand(PaymentIntentId::generate()),
        makePayChallengePort(gatewayRaisedChallenge()),
        StubPaymentIntentFirewall::allowing(),
        $challenges,
    );

    expect($aggregate->status())->toBe(PaymentIntentStatus::RequiresAction)
        ->and($challenges->verifications)->toBe(0);
});

it('refuses to confirm a challenge on a payment that is not waiting for one', function () {
    // The guard that makes the parked state meaningful: a result cannot complete a payment that
    // never parked, so an answered challenge cannot be pointed at a different payment.
    $aggregate = PaymentIntentAggregate::create(
        makeCreatePiCommand(PaymentIntentId::generate(), CaptureMethod::Immediate),
        makePaySuccessPort(),
        StubPaymentIntentFirewall::allowing(),
    );

    expect($aggregate->status())->toBe(PaymentIntentStatus::Charged)
        ->and(fn () => $aggregate->confirmChallenge(threeDSAuthentication(), makeExternallyCompletedConfirmPort()))
        ->toThrow(Techork\PaymentService\Domain\PaymentIntent\Exception\PaymentIntentChallengeNotPending::class);
});
