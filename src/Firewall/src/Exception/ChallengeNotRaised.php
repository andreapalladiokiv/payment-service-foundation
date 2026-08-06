<?php

declare(strict_types=1);

namespace Techork\PaymentService\Firewall\Exception;

use RuntimeException;
use Techork\PaymentService\Firewall\Challenge\ChallengeInitiator;

/**
 * The chain demanded a challenge and none could be raised, so it has no answer to give.
 *
 * This started life as a null: {@see \Techork\PaymentService\Domain\PaymentIntent\Port\FirewallDecision}
 * carried a `Challenge` verdict with no challenge attached, described as the truthful shape for a
 * deployment with no integration behind it. Truthful it was, and unusable: what reaches a payment
 * intent from it is a park with nothing to present, so the client has nothing to do, the state
 * cannot be left, and nothing distinguishes it from a step-up that is merely still in flight.
 *
 * That is the same defect this package removed one level up. `NoMatch` went because a caller
 * cannot act on silence; a `Challenge` with no challenge is silence wearing an action's name.
 *
 * So the null stops at the engine's edge. Inside the walk it is legitimate — a strategy decides
 * that a subject must be challenged before any artefact exists — and by the time a decision
 * leaves {@see \Techork\PaymentService\Firewall\Chain\ChainEvaluator} the artefact is there or
 * this is thrown. It sits beside {@see UnevaluableChain} and for the same reason: a firewall that
 * cannot carry out what it decided is not a degraded firewall, it is a broken one, and it says so
 * rather than answering.
 *
 * Both cases are an operator's problem, and they are different ones, which is why the messages
 * differ: nothing is installed, or something is installed and could not reach its ACS.
 */
final class ChallengeNotRaised extends RuntimeException
{
    /**
     * No {@see ChallengeInitiator} is wired up at all.
     *
     * A chain whose rules can answer `challenge` was authored against an integration that was
     * never installed. Either install one or author the chain in terms the deployment can
     * actually carry out.
     */
    public static function noInitiator(string $chain): self
    {
        return new self(sprintf(
            'Firewall chain "%s" requires a challenge, but no %s is installed to raise one.',
            $chain,
            ChallengeInitiator::class,
        ));
    }

    /**
     * An initiator is installed and returned null.
     *
     * Its own business why — an ACS that would not answer, a card the directory server does not
     * enrol, facts it could not work from. What matters here is that the subject may not proceed
     * without a challenge and there is no challenge, which is not a state a payment can be left
     * in.
     */
    public static function initiatorDeclined(string $chain): self
    {
        return new self(sprintf(
            'Firewall chain "%s" requires a challenge and the installed initiator raised none.',
            $chain,
        ));
    }
}
