<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Contract;

use Money\Money;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
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
 */
interface PaymentGatewayInterface
{
    public function tokenize(GatewayId $gatewayId, PaymentInstrument $instrument, ?BillingAddress $billingAddress = null, ?string $clientUniqueId = null): RegistrationResult;

    public function createPaymentMethod(GatewayId $gatewayId, PaymentInstrument $instrument, ?BillingAddress $billingAddress = null, ?string $clientUniqueId = null): RegistrationResult;

    public function authorize(GatewayId $gatewayId, PaymentInstrument $instrument, Money $amount, ?string $clientUniqueId = null, ?BillingAddress $billingAddress = null, ?ThreeDSResult $threeDS = null, ?string $statementDescription = null, ?string $description = null): AuthorizationResult;

    public function charge(GatewayId $gatewayId, PaymentInstrument $instrument, Money $amount, ?string $clientUniqueId = null, ?BillingAddress $billingAddress = null, ?ThreeDSResult $threeDS = null, ?string $statementDescription = null, ?string $description = null): AuthorizationResult;

    /**
     * @param  ?Money  $authorizedAmount  The originally authorized amount.
     *  Gateways without native partial capture (ConnexPay) need it to detect
     *  a partial request and fall back to void + a fresh sale with
     *  `$instrument`. Gateways with native partial capture ignore both.
     */
    public function capture(GatewayId $gatewayId, string $paymentIntentId, Money $amount, ?string $clientUniqueId = null, ?Money $authorizedAmount = null, ?PaymentInstrument $instrument = null): GatewayResult;

    public function cancel(GatewayId $gatewayId, string $paymentIntentId, ?string $clientUniqueId = null): GatewayResult;

    /**
     * @param  ?PaymentInstrument  $retryInstrument  Refund the funds onto this
     *  alternative instrument instead of the original payment source. Used
     *  when the original card can't accept the refund (expired, closed). Not
     *  every gateway supports this; impls that don't should surface a
     *  failed {@see GatewayResult} so the aggregate records `RefundFailed`.
     */
    public function refund(GatewayId $gatewayId, string $paymentIntentId, Money $amount, ?string $clientUniqueId = null, ?PaymentInstrument $retryInstrument = null): GatewayResult;

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
