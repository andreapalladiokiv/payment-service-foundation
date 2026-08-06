<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Port\Request;

use Money\Money;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\ConnectionContext;
use Techork\PaymentService\Common\ValueObject\CreditCard\CardSummary;
use Techork\PaymentService\Common\ValueObject\PaymentInitiation;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;

/**
 * What the payment-intent flow hands to its firewall: the transaction about to
 * be authorized, typed.
 *
 * This carries only what the domain actually holds. Everything derived — issuer
 * country, proxy and VPN reputation, a screening verdict — is the firewall
 * implementation's job to obtain, which is why none of it appears here.
 *
 * `connection` is nullable because a merchant-initiated payment has no request
 * origin to attribute. Note that a missing connection does NOT bypass
 * inspection: the chain still runs, and rules that lean on connection facts
 * simply do not match. Skipping evaluation because an input is absent is how a
 * firewall silently stops protecting anything.
 *
 * `gatewayId` is present when routing has already chosen a gateway, so a rule can
 * be scoped to it. It is an identifier only — the domain still learns nothing
 * about the gateway itself, which stays behind the ports.
 *
 * `initiation` says whether a cardholder is present. It is here because a chain is now
 * consulted on merchant-initiated payments too — that used to be skipped, which let a denial go
 * unasked-for on exactly the traffic nobody is watching — and a step-up cannot be carried out
 * without a cardholder. A chain that should not demand one of unattended traffic scopes its
 * rules on this rather than relying on never being asked.
 */
final readonly class PaymentIntentFirewallRequest
{
    public function __construct(
        public Money $amount,
        public CardSummary $card,
        public BillingAddress $billing,
        public ?ConnectionContext $connection = null,
        public ?PaymentIntentId $paymentIntentId = null,
        public ?string $gatewayId = null,
        public PaymentInitiation $initiation = PaymentInitiation::CardholderInitiated,
    ) {}
}
