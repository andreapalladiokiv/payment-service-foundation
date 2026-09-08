<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject;

/**
 * Everything this service can tell a caller went wrong, as one flat list.
 *
 * Flat on purpose, and modelled on Stripe's `code`, which is also flat and also long. The
 * alternative — one enum for payments that failed and another for requests that were refused —
 * describes our internals accurately and makes an SDK carry two switches over what is, from the
 * outside, one question: what happened and what do I do now. {@see wasAttempted()} keeps the
 * distinction for the callers that need it without splitting the type.
 *
 * ## What is deliberately not in here
 *
 * **An acquirer's decline reasons.** `card_declined` is one code at Stripe and the fifty-odd
 * reasons behind it live in a separate `decline_code`, and that separation is load-bearing: those
 * reasons are the issuer's words, they differ per acquirer, and ConnexPay and Nuvei do not publish
 * anything normalisable — one hands back decline codes plus AVS letters, the other `errCode`,
 * `reason`, `gwErrorCode` and `gwErrorReason` as numbers and prose. So {@see GatewayDeclined} is
 * one code and what the acquirer actually said stays in the accompanying reason text.
 *
 * **Faults.** A rule that will not compile, a port that is not installed — those are thrown as
 * `LogicException` or `RuntimeException`, reach a caller as a server error, and get no code,
 * because there is nothing a caller can do with one. A code here is a promise that the answer is
 * about the request.
 *
 * ## Naming
 *
 * Stripe's convention, because an SDK author has already read it: snake_case, subject first where
 * the subject disambiguates (`payment_intent_unexpected_state`), bare where it does not
 * (`amount_mismatch`). Spellings match Stripe's where the meaning matches, so
 * `authentication_required` means there what it means here.
 *
 * State problems collapse per resource rather than per operation — one
 * {@see PaymentIntentUnexpectedState} rather than separate codes for capture, cancel and refund.
 * The caller knows which operation it called; repeating it in the code adds nothing and makes the
 * list grow with the API.
 */
enum ErrorCode: string
{
    // ─────────────────────────────────────────────
    //  The payment was attempted and there is no money
    // ─────────────────────────────────────────────

    /**
     * Authentication is required and none was presented. Perform it and send the payment again
     * with the result.
     *
     * The one code that describes something worth retrying unchanged apart from the evidence.
     */
    case AuthenticationRequired = 'authentication_required';

    /**
     * Authentication happened and did not succeed, or what was presented did not hold up.
     *
     * Retrying with the same evidence is pointless and retrying without it is worse.
     */
    case AuthenticationFailed = 'authentication_failed';

    /**
     * A risk rule refused it. No authentication answers this and nothing about the attempt is
     * worth repeating.
     */
    case Blocked = 'blocked';

    /**
     * The acquirer refused it. Whether that is worth retrying is in the reason text, which
     * carries the acquirer's own words.
     */
    case GatewayDeclined = 'gateway_declined';

    // ─────────────────────────────────────────────
    //  The request was refused and nothing happened
    // ─────────────────────────────────────────────

    /**
     * The resource is not in a state where this operation is possible — charging something
     * already charged, capturing an authorization that has none outstanding, confirming a
     * challenge that is not pending.
     */
    case PaymentIntentUnexpectedState = 'payment_intent_unexpected_state';

    case CheckoutUnexpectedState = 'checkout_unexpected_state';

    case SubscriptionUnexpectedState = 'subscription_unexpected_state';

    /**
     * The customer cannot be asked this: an instrument it does not hold, one it already holds,
     * or one it has let go and cannot take back.
     */
    case CustomerUnexpectedState = 'customer_unexpected_state';

    /**
     * The instrument cannot be used: expired, already consumed, or otherwise spent.
     */
    case PaymentMethodUnexpectedState = 'payment_method_unexpected_state';

    /** The checkout's window has closed. Separate from its state because time, not an action, closed it. */
    case CheckoutExpired = 'checkout_expired';

    /** An amount that is zero, negative, or otherwise not a chargeable sum. */
    case InvalidChargeAmount = 'invalid_charge_amount';

    /** Two amounts that had to agree did not — a checkout against its plan, a payment against its subscription. */
    case AmountMismatch = 'amount_mismatch';

    /** Two currencies that had to agree did not, typically a refund against the payment it belongs to. */
    case CurrencyMismatch = 'currency_mismatch';

    /** More was asked for than remains — a refund beyond what is left of a captured payment. */
    case RefundExceedsAvailableAmount = 'refund_exceeds_available_amount';

    /** The capture method makes this operation impossible for this instrument or at this point. */
    case CaptureMethodUnsupported = 'capture_method_unsupported';

    /**
     * An authentication result was supplied that does not add up — claiming success while
     * carrying no cryptogram, or missing what an acquirer requires before it will be sent on.
     *
     * Distinct from {@see AuthenticationFailed}: nothing was attempted with it. The evidence was
     * rejected for being incoherent, not for being a refusal.
     */
    case InvalidAuthenticationResult = 'invalid_authentication_result';

    /** The id names nothing, or names something of another kind. */
    case ResourceMissing = 'resource_missing';

    /** Something with this id already exists and would be overwritten. */
    case ResourceAlreadyExists = 'resource_already_exists';

    /** A payment intent was named that does not belong to the subscription it was offered for. */
    case PaymentIntentSubscriptionMismatch = 'payment_intent_subscription_mismatch';

    /** A checkout carrying a plan needs a subscription and one without a plan must not have one. */
    case CheckoutPlanSubscriptionMismatch = 'checkout_plan_subscription_mismatch';

    /** The gateway this payment routes to does not implement the operation or the instrument. */
    case UnsupportedByGateway = 'unsupported_by_gateway';

    /**
     * Recorded before this list existed.
     *
     * Only ever read back, never written. It exists so replaying an event stream older than the
     * field produces something rather than failing, and so a reader can tell "nobody classified
     * this" from a claim about what happened.
     */
    case Unspecified = 'unspecified';

    /**
     * Whether a payment exists to read back.
     *
     * True means one was created and recorded as failed: there is a payment intent with a reason
     * and an event trail behind it. That is not the same as money having moved, and deliberately
     * so — {@see AuthenticationRequired} never reaches an acquirer, and the intent it leaves is
     * still a thing the merchant can fetch, audit and count. False means the request was refused
     * outright, nothing was created, and the caller's own request is what to change.
     *
     * It exists because that is the one thing a flat list cannot say on its own, and it is the
     * difference between "fix your call" and "the money did not move". Kept as a method rather
     * than a second enum so an SDK's common path stays one switch.
     */
    public function wasAttempted(): bool
    {
        return match ($this) {
            self::AuthenticationRequired,
            self::AuthenticationFailed,
            self::Blocked,
            self::GatewayDeclined => true,
            default => false,
        };
    }
}
