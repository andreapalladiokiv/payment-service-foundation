<?php

declare(strict_types=1);

use Money\Money;
use Techork\PaymentService\Common\Contract\Challenge;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\Challenge\ThreeDSChallenge;
use Techork\PaymentService\Common\ValueObject\ErrorCode;
use Techork\PaymentService\Common\ValueObject\PaymentInitiation;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ECICode;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSStatus;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSVersion;
use Techork\PaymentService\Domain\PaymentIntent\CaptureMethod;
use Techork\PaymentService\Domain\PaymentIntent\Event\PaymentIntentFailed;
use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentAggregate;
use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentStatus;
use Techork\PaymentService\Domain\PaymentIntent\Exception\ChallengeCannotBeRaised;
use Techork\PaymentService\Domain\PaymentIntent\Port\ChallengeOutcome;
use Techork\PaymentService\Domain\PaymentIntent\Port\ChallengePort;
use Techork\PaymentService\Domain\PaymentIntent\Port\FirewallDecision;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\InitiateChallengeRequest;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\VerifyChallengeRequest;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;
use Techork\PaymentService\Tests\Support\StubPaymentIntentFirewall;

/**
 * The whole 3DS arrangement, driven end to end without an HTTP layer.
 *
 * Everything else about this design is pinned one branch at a time. What this file asks is
 * different and worth asking separately: put the pieces in a line and does a real merchant
 * integration actually work — a chain demands a step-up, one is started, the cardholder answers
 * it, the payment is taken — and do the attacks the design depends on refusing actually get
 * refused.
 *
 * The port below is the reason this is a test of the scheme rather than of the aggregate. A stub
 * that answers `passed()` proves the aggregate calls it; it proves nothing about whether
 * {@see VerifyChallengeRequest} carries enough for an implementation to do its job. So this one is
 * written the way a real one has to be: a ledger of authentications the service issued, keyed by
 * the directory server's transaction id, each recording the amount and the instrument it was
 * obtained for and whether it has been spent. If the request were missing something, this class
 * could not be written, and that is the finding the test exists to produce.
 *
 * {@see initiate()} is the same argument for the other method. It answers a challenge for traffic
 * that has a cardholder and throws for traffic that does not, which is a decision no aggregate can
 * make from a command — and it is why {@see InitiateChallengeRequest} carries the initiation and
 * the instrument rather than the firewall's facts.
 */
final class IssuedAuthentications implements ChallengePort
{
    /** @var array<string, array{amount: Money, instrument: array<string, mixed>, spent: bool}> */
    private array $issued = [];

    /** How many times anything asked this port to weigh evidence. */
    public int $verifications = 0;

    /** How many times anything asked this port to start an authentication. */
    public int $initiations = 0;

    /**
     * What an MPI does when a chain has decided this payment needs authenticating: hand back
     * somewhere to send the cardholder.
     *
     * A merchant-initiated charge has nobody to send, and there is no result to invent for it
     * either, so it throws rather than answering — the rule that matched it is the thing that is
     * wrong, and an operator has to see that.
     */
    public function initiate(InitiateChallengeRequest $request): Challenge
    {
        $this->initiations++;

        if ($request->initiation !== PaymentInitiation::CardholderInitiated) {
            throw ChallengeCannotBeRaised::notPossibleForThisPayment(
                "{$request->initiation->value} payment has no cardholder to challenge",
            );
        }

        return new ThreeDSChallenge(
            authenticationId: '33333333-3333-3333-3333-333333333333',
            url: 'https://acs.issuer.example/challenge',
            payload: 'creq=eyJ0aHJlZURTU2VydmVyVHJhbnNJRCI6IjMzMzMzMzMzIn0',
        );
    }

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
    PaymentInitiation $initiation = PaymentInitiation::CardholderInitiated,
): array {
    $aggregate = PaymentIntentAggregate::create(
        makeCreatePiCommand(
            PaymentIntentId::generate(),
            CaptureMethod::Immediate,
            amount: $amount,
            instrument: $instrument,
            challengeResult: $presented,
            initiation: $initiation,
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
    // One payment, start to finish, and the "one" is the part that was missing. This test used to
    // assert a loop it never ran: attempt two was commented "same payment" and was a freshly
    // generated PaymentIntentId, because a step-up ended the first intent and there was nothing
    // left to send anything to.
    //
    // The chain wants a step-up. One is started, the payment parks on it, and it is that same
    // intent — same id, same stream — that gets paid when the cardholder comes back.
    $challenges = new IssuedAuthentications;

    [$aggregate] = threeDSCreate($challenges);

    expect($challenges->initiations)->toBe(1)
        ->and($aggregate->status())->toBe(PaymentIntentStatus::RequiresAction)
        ->and($aggregate->challenge()?->transactionId())->toBe('33333333-3333-3333-3333-333333333333');

    // The cardholder answers the ACS, and the result comes back against the payment it was raised
    // for rather than to a second one built to receive it.
    $result = threeDSAuthentication();

    $aggregate->confirmChallenge($result, makeExternallyCompletedConfirmPort());

    expect($aggregate->status())->toBe(PaymentIntentStatus::Charged)
        ->and($aggregate->challengeResult())->toBe($result);
});

it('will not start an authentication for a payment with nobody to answer it', function () {
    // The one case the old design got right and generalised to everything. A merchant-initiated
    // charge has no cardholder, so the port has nothing to raise and nothing to invent — and the
    // rule that matched unattended traffic is what has to change, which is why an operator is
    // shown this rather than a merchant being shown a decline.
    $challenges = new IssuedAuthentications;

    expect(fn () => threeDSCreate($challenges, initiation: PaymentInitiation::MerchantRecurring))
        ->toThrow(ChallengeCannotBeRaised::class, 'no cardholder to challenge');
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

it('tells the two answers to a step-up apart in a way a merchant can act on', function () {
    // Both payments meet the same rule and they end nothing alike. One is an authentication in
    // flight: the payment is alive, the cardholder has somewhere to go, and there is a way out of
    // the state. The other is evidence that did not hold up, which is over — and the code says so,
    // because a merchant matching our prose would have to tell them apart by sentence.
    $challenges = new IssuedAuthentications;

    [$parked] = threeDSCreate($challenges);
    [$ended, $rejected] = threeDSCreate($challenges, threeDSAuthentication());

    expect($parked->status())->toBe(PaymentIntentStatus::RequiresAction)
        ->and($ended->status())->toBe(PaymentIntentStatus::Failed)
        ->and($rejected?->code)->toBe(ErrorCode::AuthenticationFailed)
        ->and($rejected?->code->wasAttempted())->toBeTrue();
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
 * Both paths park, and they should: a payment that needs a cardholder to do something waits for
 * the cardholder to do it, and which side noticed is not a difference the payment should be able
 * to feel. What separates them is who raised the challenge. Here the gateway did, having already
 * opened the payment, so no `ChallengePort` is involved at all — that port is for a step-up OUR
 * firewall demanded, and the two arrangements must not reach into each other.
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
        ->and($challenges->verifications)->toBe(0)
        // And it must not have started one either, which is the newer half of the same mistake:
        // the gateway has already raised a challenge, so a second one would send the cardholder
        // to two places for one payment.
        ->and($challenges->initiations)->toBe(0);
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
