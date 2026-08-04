<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Contract;

use Money\Money;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\PaymentInitiation;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;
use Techork\PaymentService\Gateway\ValueObject\CardSpendCategory;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Common\ValueObject\CardBrand;

/**
 * Every mutating gateway operation accepts an optional `$clientUniqueId` —
 * the caller's idempotency key. Concrete implementations forward it as the
 * gateway-native idempotency mechanism (Stripe `Idempotency-Key` HTTP
 * header / SDK opt; Nuvei `clientUniqueId` body field; ConnexPay
 * `OrderNumber` body field).
 *
 * Required for replay safety: jobs and sagas can be retried after a
 * partial failure (network blip, lost ACK) and the gateway must recognize
 * the retry as the same logical operation, not a new one. The convention
 * is to pass our internal aggregate id (or aggregate id + ":suffix" when
 * one aggregate triggers multiple distinct ops at the gateway, e.g.
 * "{piId}:capture", "{piId}:cancel").
 *
 * For terminal one-shot ops with no business-meaningful retry semantics
 * ({@see updateVirtualCard}, {@see terminateVirtualCard}) the parameter is
 * intentionally omitted — those rely on natural HTTP idempotency at the
 * gateway endpoint instead.
 *
 * {@see authorizeRebilling} is the one operation that is not simply another
 * verb: it places a payment belonging to a rebilling series, which is a
 * different request from an ordinary authorization rather than the same one with
 * flags. See its own note.
 *
 * Operations that act on an existing transaction take the acquirer's
 * `$transactionReference` directly. They used to take a payment-intent id and look
 * the reference up themselves, which split the reference's lifecycle across two
 * layers — read here, written by the port — and let the same missing-row condition
 * mean two different things depending on the method. Resolving it is the port's
 * business now, alongside persisting it, so this interface talks about acquirer
 * identities and nothing else. {@see issueVirtualCard} is the exception, and only
 * because no port drives it.
 */
interface PaymentGatewayInterface
{
    public function tokenize(GatewayId $gatewayId, PaymentInstrument $instrument, ?BillingAddress $billingAddress = null, ?string $clientUniqueId = null): RegistrationResult;

    public function createPaymentMethod(GatewayId $gatewayId, PaymentInstrument $instrument, ?BillingAddress $billingAddress = null, ?string $clientUniqueId = null): RegistrationResult;

    public function authorize(GatewayId $gatewayId, PaymentInstrument $instrument, Money $amount, ?string $clientUniqueId = null, ?BillingAddress $billingAddress = null, ?ThreeDSResult $threeDS = null, ?string $statementDescription = null, ?string $description = null, PaymentInitiation $initiation = PaymentInitiation::CardholderInitiated): AuthorizationResult;

    public function charge(GatewayId $gatewayId, PaymentInstrument $instrument, Money $amount, ?string $clientUniqueId = null, ?BillingAddress $billingAddress = null, ?ThreeDSResult $threeDS = null, ?string $statementDescription = null, ?string $description = null, PaymentInitiation $initiation = PaymentInitiation::CardholderInitiated): AuthorizationResult;

    /**
     * Authorizes a payment that belongs to a rebilling series — a
     * subscription's first charge, or any of its renewals.
     *
     * A separate operation rather than arguments on {@see authorize}, because a
     * series payment carries a POSITION and an ordinary one has none. The acquirer
     * needs that position: the first payment carries the indicator opening the
     * chain, every later one the reference back to it. Nuvei words the field on
     * position rather than on who initiated the payment — "0 – For the first
     * rebilling payment. 1 – For all subsequent rebilling transactions" — and also
     * narrows the instrument, asking that subsequent payments "use only
     * userPaymentOptionId".
     *
     * Calling this at all is what says "part of a series". No field can say it: a
     * subscription opened by a present cardholder is indistinguishable from a
     * standalone checkout, both being cardholder-initiated with nothing before them.
     *
     * `$genesisReference` is the acquirer's own reference for the payment that opened
     * the series, already resolved by the caller. Null means THIS payment opens it —
     * not that there is no series, which is why the distinction needs a method
     * rather than a nullable argument on a general one.
     *
     * Authorize-only by domain condition, not by preference:
     * {@see \Techork\PaymentService\Domain\Subscription\SubscriptionAggregate::activate}
     * requires an `Authorized` intent and captures it, and that split is what makes
     * "one payment intent activates at most one subscription" true without a rule of
     * its own. So there is no capture method to pass; capture follows through
     * {@see capture}.
     */
    public function authorizeRebilling(
        GatewayId $gatewayId,
        PaymentInstrument $instrument,
        Money $amount,
        PaymentInitiation $initiation,
        ?string $genesisReference = null,
        ?string $clientUniqueId = null,
        ?BillingAddress $billingAddress = null,
        ?ThreeDSResult $threeDS = null,
        ?string $statementDescription = null,
        ?string $description = null,
    ): AuthorizationResult;

    /**
     * @param  ?Money  $authorizedAmount  The originally authorized amount.
     *  Gateways without native partial capture (ConnexPay) need it to detect
     *  a partial request and fall back to void + a fresh sale with
     *  `$instrument`. Gateways with native partial capture ignore both.
     */
    public function capture(GatewayId $gatewayId, string $transactionReference, Money $amount, ?string $clientUniqueId = null, ?Money $authorizedAmount = null, ?PaymentInstrument $instrument = null): GatewayResult;

    public function cancel(GatewayId $gatewayId, string $transactionReference, ?string $clientUniqueId = null): GatewayResult;

    /**
     * @param  ?PaymentInstrument  $retryInstrument  Refund the funds onto this
     *  alternative instrument instead of the original payment source. Used
     *  when the original card can't accept the refund (expired, closed). Not
     *  every gateway supports this; impls that don't should surface a
     *  failed {@see GatewayResult} so the aggregate records `RefundFailed`.
     */
    public function refund(GatewayId $gatewayId, string $transactionReference, Money $amount, ?string $clientUniqueId = null, ?PaymentInstrument $retryInstrument = null): GatewayResult;

    public function issueVirtualCard(
        GatewayId $gatewayId,
        string $paymentIntentId,
        Money $amountLimit,
        CardSpendCategory $spendCategory,
        ?string $firstName = null,
        ?string $lastName = null,
        ?CardBrand $cardBrand = null,
        ?string $clientUniqueId = null,
    ): VirtualCardResult;

    public function updateVirtualCard(
        GatewayId $gatewayId,
        string $cardGuid,
        Money $amountLimit,
        CardSpendCategory $spendCategory,
    ): VirtualCardResult;

    public function terminateVirtualCard(GatewayId $gatewayId, string $cardGuid): GatewayResult;
}
