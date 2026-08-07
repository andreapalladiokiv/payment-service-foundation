<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Port;

use Techork\PaymentService\Common\Contract\Challenge;
use Techork\PaymentService\Domain\PaymentIntent\Exception\ChallengeCannotBeRaised;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\InitiateChallengeRequest;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\VerifyChallengeRequest;

/**
 * Driven port for the authentication a firewall chain demanded: starting one, and checking one
 * somebody says already happened.
 *
 * Not to be confused with {@see ConfirmChallengePort}, which is the other side of the same
 * story. This port talks to whoever authenticates cardholders — a 3DS server, a directory
 * server, an MPI — and answers what the authentication did. That one talks to the gateway and
 * places the payment once the authentication is over. Two systems, two ports.
 *
 * ## Why it is the aggregate's and not the firewall's
 *
 * It used to be `Firewall\Challenge\ChallengeInitiator`, taking a chain name and the rules' fact
 * bag. That could not work, and the reason is not incidental: facts are what rules match on, so
 * they hold a BIN and a last four and deliberately never a card number, while an authentication
 * request needs the pan, the expiry and the holder. The firewall had nothing to raise a step-up
 * with, and widening the fact vocabulary to give it some would have turned the language an
 * operator authors rules in into an argument list for one protocol.
 *
 * The aggregate has the instrument, and it already owns the port that finishes a challenge. So
 * the firewall decides and this carries out, which is also why {@see FirewallDecision} no longer
 * has anywhere to put an artefact.
 *
 * ## What implementations owe
 *
 * {@see verify()} answers with a {@see ChallengeOutcome} and MUST NOT throw for either ending:
 * an authentication that holds up and one that does not are both answers. Throw for
 * infrastructure that cannot be reached, the same rule every other port here follows — a
 * provider that is down is not a payment that failed authentication.
 *
 * {@see initiate()} has no such vocabulary and does not need one, because it is asked only after
 * a chain has decided a step-up must happen: the answer is the artefact to present, or nothing
 * this payment can proceed on. A step-up that cannot be carried out for this payment — a
 * merchant-initiated charge with no cardholder to answer, a channel that cannot render an ACS
 * page — is a rule that matched traffic it should not have, so it throws
 * {@see ChallengeCannotBeRaised} and an operator reads it. Better still is a chain whose step-up
 * rules do not match such traffic in the first place, which is what the
 * `payment_intent.initiation` fact is for.
 */
interface ChallengePort
{
    /**
     * Start an authentication for a payment a chain will not let through without one, and answer
     * what the cardholder is to be sent to.
     *
     * The artefact is the whole return, and it is required: the payment parks on it and a client
     * renders it, so an implementation that decided a step-up is needed but obtained nothing to
     * show has not raised one. There is no frictionless ending here either — an authentication
     * that completes with nothing for the cardholder to do is one this port never had to start,
     * and its result reaches the payment through {@see verify()} on the next call.
     *
     * @throws ChallengeCannotBeRaised
     */
    public function initiate(InitiateChallengeRequest $request): Challenge;

    /**
     * Establish what an already-presented result is actually worth.
     *
     * Answer {@see ChallengeOutcome::passed()} with the PROVIDER's result rather than the one
     * presented wherever the two can differ — it is the answer the payment will carry to the
     * acquirer as evidence, and asking is pointless if the reply is the question.
     */
    public function verify(VerifyChallengeRequest $request): ChallengeOutcome;
}
