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
        $billing = $request->billing;
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
                'billing_address' => [
                    'first_name' => $billing->firstName,
                    'last_name' => $billing->lastName,
                    'country' => (string) $billing->country,
                    'city' => $billing->city,
                    'postal_code' => $billing->postalCode,
                    'state' => $billing->state !== null ? (string) $billing->state : null,
                    'email' => $billing->email !== null ? (string) $billing->email : null,
                    'phone' => $billing->phone !== null ? (string) $billing->phone : null,
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
