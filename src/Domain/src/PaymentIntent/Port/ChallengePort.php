<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Port;

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
 * Both methods answer with a {@see ChallengeOutcome} and MUST NOT throw for any of the three:
 * a step-up to present, an authentication that passed without one, and a refusal are all
 * answers. Throw for infrastructure that cannot be reached, the same rule every other port here
 * follows — a provider that is down is not a payment that failed authentication.
 *
 * A merchant-initiated payment reaches {@see initiate()} like any other, and there is no
 * cardholder to answer anything: {@see ChallengeOutcome::refused()} is the honest reply.
 * Better still is a chain whose step-up rules do not match one, which is what the
 * `payment_intent.initiation` fact is for.
 */
interface ChallengePort
{
    /**
     * Establish what an already-presented result is actually worth.
     *
     * Answer {@see ChallengeOutcome::passed()} with the PROVIDER's result rather than the one
     * presented wherever the two can differ — it is the answer the payment will carry to the
     * acquirer as evidence, and asking is pointless if the reply is the question.
     */
    public function verify(VerifyChallengeRequest $request): ChallengeOutcome;
}
