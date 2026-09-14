<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\ValueObject;

/**
 * One thing a card network counts as evidence in a dispute, in this project's own
 * vocabulary.
 *
 * The names are deliberately not any provider's field names. A Stripe evidence field, a
 * Nuvei PDF and a ConnexPay portal package are three encodings of the same underlying
 * facts, and the requirement table has to be stated once, before either submission
 * adapter exists. Adapters map outward from here; nothing maps back in.
 *
 * Two groups, and the split between them is the point of the type:
 *
 *  - **Supplied by the system** — facts the payment already carries. They exist because
 *    the payment ran through us, so nobody has to be asked for them: the AVS/CVV result
 *    ({@see \Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult}), the 3DS
 *    outcome and the liability shift it buys ({@see \Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult}),
 *    the buyer's address ({@see \Techork\PaymentService\Common\ValueObject\IpAddress}),
 *    when the authorization happened, the card's history with us, and the descriptor
 *    ({@see \Techork\PaymentService\Common\ValueObject\MerchantDescriptor}).
 *  - **Supplied by the project** — documents only the merchant behind the payment can
 *    produce. Nothing in this library can manufacture a signed delivery note, and a
 *    submission assembled without one is a case lost by default.
 *
 * The group is a property of the type rather than of the template, so
 * {@see EvidenceRequirements} can refuse a template that files a type under the wrong
 * group. Without that rule the two lists would be two arrays nothing distinguishes, and
 * "the system supplies it" would be a comment instead of an invariant.
 */
enum EvidenceType: string
{
    // ----- Supplied by the system, out of the payment itself -----

    /** The AVS and CVV results as the issuer returned them. */
    case AvsCvvResult = 'avs_cvv_result';

    /**
     * Whether 3DS ran, and whether it moved liability to the issuer.
     *
     * One type rather than two: a status on its own answers nothing the network asks,
     * and the liability shift is a conclusion drawn from the status and the
     * authentication value, never a separate observation to be collected.
     */
    case ThreeDsStatusAndLiabilityShift = 'three_ds_status_and_liability_shift';

    /**
     * Where the buyer was when the payment was initiated.
     *
     * Remote payments only — a transaction at a terminal has no buyer IP and no honest
     * way to produce one, which is why the templates below do not all carry it.
     */
    case BuyerIpAddress = 'buyer_ip_address';

    /** When the authorization happened — the anchor for every "was it in time" claim. */
    case AuthorizationTimestamp = 'authorization_timestamp';

    /**
     * This card's history with us: earlier charges, refunds, disputes already answered.
     *
     * Fraud defences turn on it — a card used successfully for months and disputed once
     * reads differently from a card disputed on its first use — and we are the only
     * party in the chain holding it.
     */
    case PaymentCardHistory = 'payment_card_history';

    /**
     * The descriptor as it appeared on the statement, which is what the cardholder is
     * asked to recognise when they say they do not recognise the charge.
     */
    case StatementDescriptor = 'statement_descriptor';

    // ----- Supplied by the project (the merchant behind the payment) -----

    /**
     * Proof that the goods arrived, or that the service was performed.
     *
     * Covers both a carrier's delivery confirmation and a signed receipt taken at a
     * terminal: the networks treat "was it delivered" and "was it provided" as one
     * question with one answer, and it is the question most reason codes reduce to.
     */
    case ProofOfDeliveryOrService = 'proof_of_delivery_or_service';

    /** The exchange with the buyer — order confirmations, support threads, complaints. */
    case CustomerCorrespondence = 'customer_correspondence';

    /**
     * The terms the buyer accepted, *together with* the record of the acceptance.
     *
     * The record is not a nicety and not a detail of the wording: terms nobody can be
     * shown to have accepted are not evidence, and a submission carrying the text alone
     * is refused on exactly that ground.
     */
    case TermsOfServiceAcceptance = 'terms_of_service_acceptance';

    /** The cancellation terms in force at the time of the sale. */
    case CancellationPolicy = 'cancellation_policy';

    /** The buyer's own cancellation, or the acknowledgement we sent back for it. */
    case CancellationConfirmation = 'cancellation_confirmation';

    /**
     * Which side of the case is expected to produce this.
     *
     * An exhaustive `match` rather than a constant list of "system" cases: a new case
     * with no decision made about its group becomes a static-analysis error instead of
     * silently reading as project-supplied evidence nobody can produce.
     */
    public function isSystemSupplied(): bool
    {
        return match ($this) {
            self::AvsCvvResult,
            self::ThreeDsStatusAndLiabilityShift,
            self::BuyerIpAddress,
            self::AuthorizationTimestamp,
            self::PaymentCardHistory,
            self::StatementDescriptor => true,

            self::ProofOfDeliveryOrService,
            self::CustomerCorrespondence,
            self::TermsOfServiceAcceptance,
            self::CancellationPolicy,
            self::CancellationConfirmation => false,
        };
    }
}
