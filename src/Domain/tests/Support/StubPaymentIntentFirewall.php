<?php

declare(strict_types=1);

namespace Techork\PaymentService\Tests\Support;

use Techork\PaymentService\Domain\PaymentIntent\Port\FirewallDecision;
use Techork\PaymentService\Domain\PaymentIntent\Port\PaymentIntentFirewallPort;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\PaymentIntentFirewallRequest;

/**
 * A firewall with a fixed answer, for tests about something other than the
 * firewall itself.
 *
 * Defaults to accepting, because most aggregate tests are about what happens
 * once a payment is allowed through. Note this is the opposite of the production
 * default ({@see \Techork\PaymentService\Domain\PaymentIntent\Port\NullPaymentIntentFirewall}
 * refuses): a test fixture may be permissive, a missing firewall may not.
 */
final class StubPaymentIntentFirewall implements PaymentIntentFirewallPort
{
    public ?PaymentIntentFirewallRequest $received = null;

    private function __construct(private readonly FirewallDecision $decision) {}

    public static function allowing(): self
    {
        return new self(FirewallDecision::allow('stub'));
    }

    public static function denying(): self
    {
        return new self(FirewallDecision::deny('stub'));
    }

    public static function returning(FirewallDecision $decision): self
    {
        return new self($decision);
    }

    public function evaluate(PaymentIntentFirewallRequest $request): FirewallDecision
    {
        $this->received = $request;

        return $this->decision;
    }
}
