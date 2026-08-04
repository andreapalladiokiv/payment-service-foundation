<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Command;

use Money\Money;
use Techork\PaymentService\Common\Contract\ChallengeResult;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\ConnectionContext;
use Techork\PaymentService\Common\ValueObject\MerchantDescriptor;
use Techork\PaymentService\Domain\PaymentIntent\CaptureMethod;
use Techork\PaymentService\Common\ValueObject\PaymentInitiation;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;

interface CreatePaymentIntentCommand
{
    public function paymentIntentId(): PaymentIntentId;

    public function amount(): Money;

    public function instrument(): PaymentInstrument;

    public function captureMethod(): CaptureMethod;

    public function billingAddress(): BillingAddress;

    /**
     * What the cardholder will see on their statement. May be empty, in which
     * case the acquirer falls back to the merchant's configured default.
     */
    public function merchantDescriptor(): MerchantDescriptor;

    /**
     * Free-text note about the payment, for the merchant's own records. Never
     * shown to the cardholder and never sent to the networks, so unlike
     * {@see merchantDescriptor()} it carries no format constraints.
     */
    public function description(): string;

    /** @return array<string, mixed> */
    public function metadata(): array;

    /**
     * Optional pre-authenticated challenge result from an external MPI
     * (e.g. 3dsintegrator). When present, the aggregate forwards it to
     * the gateway as evidence to claim the liability shift.
     */
    public function challengeResult(): ?ChallengeResult;

    /**
     * How this payment was initiated (Stored Credential Framework). Gates the
     * cardholder-facing controls: the firewall and 3DS step-up run only for a
     * cardholder-initiated transaction.
     */
    public function initiation(): PaymentInitiation;

    /**
     * Where the request came from — the signals the firewall inspects (IP,
     * user agent, an optional device fingerprint).
     *
     * Null when there is no cardholder present to attribute a connection to, as
     * with a merchant-initiated payment; the firewall is not consulted then
     * either.
     */
    public function connection(): ?ConnectionContext;

    /**
     * The payment whose authorization began this instrument's stored-credential
     * series — the "genesis" — or null when this payment is not continuing one.
     *
     * A domain identity, deliberately, not a gateway reference: the acquirers that
     * demand an anchor each name it differently (Nuvei's relatedTransactionId,
     * ConnexPay's initial Sale Guid) and a stream carrying one of those would be
     * both unportable and a breach of the promise below. The port resolves this id
     * to whatever the chosen gateway wants, through the same lookup capture and
     * refund already use.
     *
     * Null is not "no series". It means this payment does not claim to continue
     * one — which is the truth for a first payment, and a lie for a renewal. The
     * foundation cannot tell the difference, because whether a stored credential
     * has a prior transaction is a fact about the acquirer's records, not about
     * this aggregate. Supplying it is the caller's job; see the note on
     * {@see initiation()} for the other half of the same contract.
     */
    public function genesisPaymentIntentId(): ?PaymentIntentId;

    /**
     * The gateway this payment will run through, when routing has already chosen
     * one, so a rule can be scoped to it.
     *
     * This is an identifier only — the domain still learns nothing about the
     * gateway itself, which stays behind the ports.
     */
    public function gatewayId(): ?string;
}
