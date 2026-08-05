<?php

declare(strict_types=1);

namespace Techork\PaymentService\Firewall\PaymentIntent;

use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Techork\PaymentService\Domain\PaymentIntent\Port\FirewallDecision;
use Techork\PaymentService\Domain\PaymentIntent\Port\PaymentIntentFirewallPort;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\PaymentIntentFirewallRequest;
use Techork\PaymentService\Firewall\Chain\ChainEvaluator;
use Techork\PaymentService\Firewall\Fact\FactCollector;

/**
 * {@see PaymentIntentFirewallPort} backed by the rule DSL.
 *
 * It takes the domain's typed request and does the two things the domain should
 * not have to: assembles the facts — what the request carried, plus whatever the
 * enrichment suppliers can add — and walks the chain.
 *
 * The chain answers with one of three actions, including for a subject its rules did not
 * mention — see {@see \Techork\PaymentService\Firewall\Chain\ChainStrategy}, which is where a
 * chain says whether silence means allow or deny.
 *
 * Note the ordering: enrichment suppliers come AFTER the request supplier, so a
 * looked-up fact overrides the corresponding value from the request rather than
 * the reverse. That matters for facts both can produce — an issuer country read
 * from BIN intelligence is better evidence than anything the caller asserted.
 */
final readonly class PaymentIntentFirewall implements PaymentIntentFirewallPort
{
    /**
     * The chain a payment-intent authorization is inspected against. A single
     * chain, because this port is the single inspection point for the flow;
     * override it only if the application names its chains differently.
     */
    public const string CHAIN = 'payment_intent.authorization';

    public function __construct(
        private ChainEvaluator $chain,
        private EnrichmentSuppliers $enrichment,
        private LoggerInterface $logger = new NullLogger(),
        private string $chainName = self::CHAIN,
    ) {}

    #[Override]
    public function evaluate(PaymentIntentFirewallRequest $request): FirewallDecision
    {
        $facts = new FactCollector(
            [new RequestFactSupplier($request), ...$this->enrichment->for($request)],
            $this->logger,
        );

        return $this->chain->evaluate($this->chainName, $facts->collect());
    }
}
