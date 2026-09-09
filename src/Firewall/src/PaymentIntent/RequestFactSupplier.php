<?php

declare(strict_types=1);

namespace Techork\PaymentService\Firewall\PaymentIntent;

use Override;
use Techork\PaymentService\Common\Contract\FactSupplier;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\PaymentIntentFirewallRequest;

/**
 * The facts derivable from the domain request alone — no lookups, no network.
 *
 * Everything here is what the caller already handed over: the card as it was
 * presented, the billing address, the request origin, and the transaction.
 * Enrichment (issuer country, proxy reputation, a screening verdict) comes from
 * other suppliers layered over this one.
 *
 * Value objects are stringified at this boundary, so what reaches an authored
 * rule is plain data and never domain behaviour.
 *
 * A connection-less request (a merchant-initiated payment) still yields the
 * `connection` branch, with its fields null: the rules must be able to ask "was
 * there no origin?" rather than have the question silently disappear.
 */
final readonly class RequestFactSupplier implements FactSupplier
{
    public function __construct(private PaymentIntentFirewallRequest $request) {}

    #[Override]
    public function facts(): array
    {
        $request = $this->request;
        $customer = $request->customer;
        $identity = $customer->identity;
        $billing = $customer->billingAddress;
        $connection = $request->connection;

        return [
            'payment_method' => [
                'source' => [
                    'bin' => $request->card->bin,
                    'last4' => $request->card->last4,
                    'brand' => $request->card->brand->value,
                    'expiry_month' => (int) $request->card->expiration->format('m'),
                    'expiry_year' => (int) $request->card->expiration->format('Y'),
                    'is_expired' => $request->card->expiration->expired(),
                ],
                // The key stays `billing_address` although four of its eight fields now come
                // off the customer's identity rather than their address. It is published
                // vocabulary: rules already written and stored match on these paths, and
                // renaming them is a migration of somebody's rule set, not a refactor. What the
                // rename would buy is accuracy in a name; what it costs is every rule that
                // mentions a payer silently ceasing to match.
                'billing_address' => [
                    'first_name' => $identity->firstName,
                    'last_name' => $identity->lastName,
                    'country' => (string) $billing->country,
                    'city' => $billing->city,
                    'postal_code' => $billing->postalCode,
                    'state' => $billing->state !== null ? (string) $billing->state : null,
                    'email' => $identity->email !== null ? (string) $identity->email : null,
                    'phone' => $identity->phone !== null ? (string) $identity->phone : null,
                ],
                'connection' => [
                    'ip' => $connection !== null ? (string) $connection->ipAddress : null,
                    'user_agent' => $connection?->userAgent,
                    'has_device_token' => $connection?->deviceToken !== null,
                ],
            ],
            'payment_intent' => [
                'id' => $request->paymentIntentId?->toString(),
                'amount' => (int) $request->amount->getAmount(),
                'currency' => $request->amount->getCurrency()->getCode(),
                'gateway_id' => $request->gatewayId,
                // Both spellings, because a rule needs either depending on what it is saying. The
                // boolean is what a step-up rule guards itself with; the value distinguishes the
                // kinds of unattended payment from each other, which a rule about recurring
                // billing needs and a boolean cannot express.
                'initiation' => $request->initiation->value,
                'is_cardholder_initiated' => ! $request->initiation->isMerchantInitiated(),
            ],
        ];
    }
}
