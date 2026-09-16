<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Decorator;

use Override;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\CreditCard\CardSummaryExtractor;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;
use Techork\PaymentService\Gateway\Command\CancelCommand;
use Techork\PaymentService\Gateway\Command\CaptureCommand;
use Techork\PaymentService\Gateway\Command\PlacementCommand;
use Techork\PaymentService\Gateway\Command\RebillingCommand;
use Techork\PaymentService\Gateway\Command\RefundCommand;
use Techork\PaymentService\Gateway\Command\RegisterCustomerCommand;
use Techork\PaymentService\Gateway\Command\VaultCommand;
use Techork\PaymentService\Gateway\Contract\AuthorizationResult;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Gateway\Contract\RegistrationResult;
use Techork\PaymentService\Gateway\Logger\GatewayLoggerInterface;
use Techork\PaymentService\Gateway\Role\AcquiringGateway;

/**
 * Logs the request and the answer around whatever it wraps.
 *
 * Each operation writes its own context, from the typed properties of the command and the result
 * it already holds. No type carries a projection method and there is no central table of them:
 * what a log line may say is decided here, once per operation, where the audience is known.
 *
 * Only non-sensitive facts are named. A customer is its id and never `Customer::toArray()`; an
 * instrument is the PCI-safe summary and never `PaymentInstrument::toPayload()`; a challenge is
 * its transaction id and never the transport payload assembled for the cardholder's browser.
 * This is a white list written where the log line is written, not a black list applied to a
 * richer shape afterwards — scrubbing is a hope, because `PaymentMethod::toPayload()` nests a
 * whole instrument, and a customer, under a key named by the instrument's own type, so a removal
 * pass would have to know every sensitive key at every depth.
 *
 * Outside {@see FailureBoundary}, so what it records is the answer a caller receives, including
 * a provider error already folded into a failed result. A marked refusal propagates without a
 * response line, which is accurate: nothing answered. Because it sits outside that boundary
 * nothing here may throw, which is why the helpers below are total and read only public state.
 *
 * What writing the context here costs: the compiler checks every property name, but it cannot
 * tell you that a richer result arrived where a base one was declared. {@see around()} logs the
 * three keys every {@see GatewayResult} carries; if an operation routed through it ever returns
 * a subclass with signals of its own, they have to be added to that literal deliberately.
 */
final readonly class LoggingGateway implements AcquiringGateway
{
    public function __construct(
        private AcquiringGateway $inner,
        private GatewayLoggerInterface $logger,
        private string $gatewayName,
    ) {}

    #[Override]
    public function authorize(PlacementCommand $command): AuthorizationResult
    {
        return $this->aroundAuthorization('authorize', $this->placement($command), $command->clientUniqueId,
            fn (): AuthorizationResult => $this->inner->authorize($command));
    }

    #[Override]
    public function charge(PlacementCommand $command): AuthorizationResult
    {
        return $this->aroundAuthorization('charge', $this->placement($command), $command->clientUniqueId,
            fn (): AuthorizationResult => $this->inner->charge($command));
    }

    #[Override]
    public function authorizeRebilling(RebillingCommand $command): AuthorizationResult
    {
        return $this->aroundAuthorization('authorizeRebilling', [
            'gatewayId' => $command->gatewayId->toString(),
            'amount' => $command->amount,
            'instrument' => self::instrument($command->instrument),
            'clientUniqueId' => $command->clientUniqueId,
            'threeDS' => self::threeDS($command->threeDS),
            'statementDescription' => $command->statementDescription,
            'description' => $command->description,
            'initiation' => $command->initiation->value,
            'genesisReference' => $command->genesisReference,
            'customer' => $command->customer?->id->toString(),
        ], $command->clientUniqueId, fn (): AuthorizationResult => $this->inner->authorizeRebilling($command));
    }

    #[Override]
    public function tokenize(VaultCommand $command): RegistrationResult
    {
        return $this->aroundRegistration('tokenize', $this->vault($command), $command->clientUniqueId,
            fn (): RegistrationResult => $this->inner->tokenize($command));
    }

    #[Override]
    public function registerPaymentMethod(VaultCommand $command): RegistrationResult
    {
        return $this->aroundRegistration('registerPaymentMethod', $this->vault($command), $command->clientUniqueId,
            fn (): RegistrationResult => $this->inner->registerPaymentMethod($command));
    }

    #[Override]
    public function registerCustomer(RegisterCustomerCommand $command): RegistrationResult
    {
        // Keyed on our customer id rather than on a `clientUniqueId`, which this operation has
        // none of: registering a customer is not a payment, so there is no per-attempt id to
        // correlate on and the customer is the only thing the two log lines share.
        return $this->aroundRegistration('registerCustomer', [
            'gatewayId' => $command->gatewayId->toString(),
            'customer' => $command->customer->id->toString(),
        ], $command->customer->id->toString(), fn (): RegistrationResult => $this->inner->registerCustomer($command));
    }

    #[Override]
    public function capture(CaptureCommand $command): GatewayResult
    {
        return $this->around('capture', [
            'gatewayId' => $command->gatewayId->toString(),
            'transactionReference' => $command->transactionReference,
            'amount' => $command->amount,
            'clientUniqueId' => $command->clientUniqueId,
            'authorizedAmount' => $command->authorizedAmount,
            'instrument' => self::instrument($command->instrument),
            'customer' => $command->customer?->id->toString(),
        ], $command->clientUniqueId, fn (): GatewayResult => $this->inner->capture($command));
    }

    #[Override]
    public function cancel(CancelCommand $command): GatewayResult
    {
        return $this->around('cancel', [
            'gatewayId' => $command->gatewayId->toString(),
            'transactionReference' => $command->transactionReference,
            'clientUniqueId' => $command->clientUniqueId,
        ], $command->clientUniqueId, fn (): GatewayResult => $this->inner->cancel($command));
    }

    #[Override]
    public function refund(RefundCommand $command): GatewayResult
    {
        return $this->around('refund', $this->refundContext($command), $command->clientUniqueId,
            fn (): GatewayResult => $this->inner->refund($command));
    }

    #[Override]
    public function retryRefund(RefundCommand $command): GatewayResult
    {
        return $this->around('retryRefund', $this->refundContext($command), $command->clientUniqueId,
            fn (): GatewayResult => $this->inner->retryRefund($command));
    }

    /**
     * The request half of a placement, shared by `authorize` and `charge` because both take the
     * same command and differ only in which operation the provider is asked for.
     *
     * @return array<string, mixed>
     */
    private function placement(PlacementCommand $command): array
    {
        return [
            'gatewayId' => $command->gatewayId->toString(),
            'amount' => $command->amount,
            'instrument' => self::instrument($command->instrument),
            'clientUniqueId' => $command->clientUniqueId,
            'threeDS' => self::threeDS($command->threeDS),
            'statementDescription' => $command->statementDescription,
            'description' => $command->description,
            'initiation' => $command->initiation->value,
            'customer' => $command->customer?->id->toString(),
        ];
    }

    /**
     * The request half of a vault, shared by `tokenize` and `registerPaymentMethod` for the same
     * reason as {@see placement()}.
     *
     * @return array<string, mixed>
     */
    private function vault(VaultCommand $command): array
    {
        return [
            'gatewayId' => $command->gatewayId->toString(),
            'instrument' => self::instrument($command->instrument),
            'clientUniqueId' => $command->clientUniqueId,
            'customer' => $command->customer?->id->toString(),
        ];
    }

    /**
     * The request half of a refund, shared by `refund` and `retryRefund` for the same reason as
     * {@see placement()}.
     *
     * @return array<string, mixed>
     */
    private function refundContext(RefundCommand $command): array
    {
        return [
            'gatewayId' => $command->gatewayId->toString(),
            'transactionReference' => $command->transactionReference,
            'amount' => $command->amount,
            'clientUniqueId' => $command->clientUniqueId,
            'retryInstrument' => self::instrument($command->retryInstrument),
            'customer' => $command->customer?->id->toString(),
        ];
    }

    /**
     * The instrument as a log line may see it: the PCI-safe summary, never the payload.
     *
     * Built from {@see CardSummaryExtractor} rather than by deleting keys from `toPayload()`,
     * because such a removal would have to know every sensitive key at every depth and would
     * still miss the one nested under `PaymentMethod`'s type-named key. The holder is dropped
     * here: it is the one field of the summary marked `#[Pii]`, and correlation does not need a
     * name, only a way to recognise the card.
     *
     * The summary's `bin` is emitted as `first6` so the key stays the one the log line has always
     * carried. The three `*_check` fields the wire payload also held are not repeated here — the
     * same signals arrive on the result side as `addressLineCheck`, `postalCodeCheck` and
     * `cvcCheck`, and one place to read them is better than two.
     *
     * @return array<string, mixed>|null
     */
    private static function instrument(?PaymentInstrument $instrument): ?array
    {
        if ($instrument === null) {
            return null;
        }

        $summary = CardSummaryExtractor::from($instrument);

        return [
            'type' => $instrument::type(),
            'first6' => $summary?->bin,
            'last4' => $summary?->last4,
            'brand' => $summary?->brand->value,
            'expiration' => $summary?->expiration->format('my'),
        ];
    }

    /**
     * The 3DS result with the authentication value truncated to its last four characters.
     *
     * The CAVV / UCAF proves the cardholder authenticated: it goes to the acquirer with the
     * payment, and a log line carrying it would hand whoever reads the log the ability to claim a
     * liability shift they did not earn. Everything else in the result is correlation data and is
     * logged as it stands.
     *
     * @return array<string, mixed>|null
     */
    private static function threeDS(?ThreeDSResult $result): ?array
    {
        if ($result === null) {
            return null;
        }

        return [
            'status' => $result->status->value,
            'authentication_value' => $result->authenticationValue === null
                ? null
                : '…'.substr($result->authenticationValue, -4),
            'eci' => $result->eci?->value,
            'ds_transaction_id' => $result->dsTransactionId,
            'acs_transaction_id' => $result->acsTransactionId,
            'version' => $result->version?->value,
        ];
    }

    /**
     * @param  array<string, mixed>  $request
     * @param  callable(): GatewayResult  $call
     */
    private function around(string $operation, array $request, ?string $clientUniqueId, callable $call): GatewayResult
    {
        $this->logger->log("Gateway {$operation} request", [
            'gatewayName' => $this->gatewayName,
            ...$request,
        ]);

        $result = $call();

        $this->logger->log("Gateway {$operation} response", [
            'clientUniqueId' => $clientUniqueId,
            'success' => $result->success,
            'reference' => $result->reference,
            'message' => $result->message,
        ]);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $request
     * @param  callable(): AuthorizationResult  $call
     */
    private function aroundAuthorization(string $operation, array $request, ?string $clientUniqueId, callable $call): AuthorizationResult
    {
        $this->logger->log("Gateway {$operation} request", [
            'gatewayName' => $this->gatewayName,
            ...$request,
        ]);

        $result = $call();

        $this->logger->log("Gateway {$operation} response", [
            'clientUniqueId' => $clientUniqueId,
            ...self::authorization($result),
        ]);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $request
     * @param  callable(): RegistrationResult  $call
     */
    private function aroundRegistration(string $operation, array $request, ?string $clientUniqueId, callable $call): RegistrationResult
    {
        $this->logger->log("Gateway {$operation} request", [
            'gatewayName' => $this->gatewayName,
            ...$request,
        ]);

        $result = $call();

        $this->logger->log("Gateway {$operation} response", [
            'clientUniqueId' => $clientUniqueId,
            ...self::registration($result),
        ]);

        return $result;
    }

    /**
     * What an authorization carries beyond a bare outcome.
     *
     * A challenge and the AVS / CVC checks are the whole reason this result is not a plain
     * {@see GatewayResult}, and a log line that omitted them would describe a payment awaiting a
     * step-up as an ordinary success. The challenge is named by its transaction id alone: the
     * form fields it carries are transport for the cardholder's browser — a hosted page's
     * signature among them — and a log is a different audience with a different vocabulary.
     *
     * @return array<string, mixed>
     */
    private static function authorization(AuthorizationResult $result): array
    {
        return [
            'success' => $result->success,
            'reference' => $result->reference,
            'message' => $result->message,
            'requiresAction' => $result->isRequiresAction(),
            'challenge' => $result->challenge?->transactionId(),
            'addressLineCheck' => $result->addressLineCheck?->value,
            'postalCodeCheck' => $result->postalCodeCheck?->value,
            'cvcCheck' => $result->cvcCheck?->value,
        ];
    }

    /**
     * What a registration carries beyond a bare outcome: the provider-side customer it created,
     * and the checks the provider ran while vaulting.
     *
     * @return array<string, mixed>
     */
    private static function registration(RegistrationResult $result): array
    {
        return [
            'success' => $result->success,
            'reference' => $result->reference,
            'message' => $result->message,
            'customerReference' => $result->customerReference,
            'addressLineCheck' => $result->addressLineCheck?->value,
            'postalCodeCheck' => $result->postalCodeCheck?->value,
            'cvcCheck' => $result->cvcCheck?->value,
        ];
    }
}
