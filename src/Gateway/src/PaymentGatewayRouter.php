<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway;

use Money\Money;
use Omnipay\Common\Message\ResponseInterface;
use RuntimeException;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\PaymentInitiation;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;
use Techork\PaymentService\Gateway\Contract\AuthorizationResult;
use Techork\PaymentService\Gateway\Contract\CardChecksProvider;
use Techork\PaymentService\Gateway\Contract\ChallengeProvider;
use Techork\PaymentService\Gateway\Contract\CustomerReferenceProvider;
use Techork\PaymentService\Gateway\Contract\GatewayCredentialRepository;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Gateway\Contract\GatewayTransactionRepository;
use Techork\PaymentService\Gateway\Contract\PaymentGatewayInterface;
use Techork\PaymentService\Gateway\Contract\RegistrationResult;
use Techork\PaymentService\Gateway\Contract\ConvertedAmountProvider;
use Techork\PaymentService\Gateway\Contract\TransactionMetadataProvider;
use Techork\PaymentService\Gateway\Contract\VirtualCardResponseInterface;
use Techork\PaymentService\Gateway\Contract\VirtualCardResult;
use Techork\PaymentService\Gateway\Exception\UnsupportedByGateway;
use Techork\PaymentService\Gateway\Logger\GatewayLoggerInterface;
use Techork\PaymentService\Gateway\Logger\NullGatewayLogger;
use Techork\PaymentService\Gateway\ValueObject\CardSpendCategory;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Throwable;

final readonly class PaymentGatewayRouter implements PaymentGatewayInterface
{
    public function __construct(
        private GatewayFactory $gatewayFactory,
        private DecryptInterface $decrypter,
        private GatewayCredentialRepository $credentialRepository,
        private GatewayInstrumentRepository $referenceRepository,
        private GatewayTransactionRepository $transactionRepository,
        private GatewayLoggerInterface $logger = new NullGatewayLogger(),
    ) {}

    public function tokenize(GatewayId $gatewayId, PaymentInstrument $instrument, ?BillingAddress $billingAddress = null, ?string $clientUniqueId = null): RegistrationResult
    {
        $credential = $this->credentialRepository->findOrFail($gatewayId);
        $omnipay = $this->gatewayFactory->createForCredential($credential);

        $params = [
            'instrument' => $instrument,
            'decrypter' => $this->decrypter,
            'clientUniqueId' => $clientUniqueId,
            ...($billingAddress === null ? [] : ['billingAddress' => $billingAddress]),
        ];

        $this->logger->log('Gateway tokenize request', [
            'gatewayId' => $gatewayId->toString(),
            'gatewayName' => $credential->getGatewayName(),
            'instrument' => $instrument->toPayload(),
            'clientUniqueId' => $clientUniqueId,
            ...($billingAddress === null ? [] : ['billingAddress' => $billingAddress->toArray()]),
        ]);

        $result = $this->buildRegistration(fn () => $omnipay->createCard($params)->send());

        $this->logger->log('Gateway tokenize response', [
            'clientUniqueId' => $clientUniqueId,
            'success' => $result->success,
            'reference' => $result->reference,
            'message' => $result->message,
            'customerReference' => $result->customerReference,
            'addressLineCheck' => $result->addressLineCheck,
            'postalCodeCheck' => $result->postalCodeCheck,
            'cvcCheck' => $result->cvcCheck,
        ]);

        return $result;
    }

    public function createPaymentMethod(GatewayId $gatewayId, PaymentInstrument $instrument, ?BillingAddress $billingAddress = null, ?string $clientUniqueId = null): RegistrationResult
    {
        $credential = $this->credentialRepository->findOrFail($gatewayId);
        $omnipay = $this->gatewayFactory->createForCredential($credential);

        $this->logger->log('Gateway createPaymentMethod request', [
            'gatewayId' => $gatewayId->toString(),
            'gatewayName' => $credential->getGatewayName(),
            'instrument' => $instrument->toPayload(),
            'billingAddress' => $billingAddress->toArray(),
            'clientUniqueId' => $clientUniqueId,
        ]);

        $result = $this->buildRegistration(fn () => $omnipay->createPaymentMethod([
            'instrument' => $instrument,
            'gateway' => $credential,
            'decrypter' => $this->decrypter,
            'referenceResolver' => $this->referenceRepository,
            'billingAddress' => $billingAddress,
            'clientUniqueId' => $clientUniqueId,
        ])->send());

        $this->logger->log('Gateway createPaymentMethod response', [
            'clientUniqueId' => $clientUniqueId,
            'success' => $result->success,
            'reference' => $result->reference,
            'message' => $result->message,
            'customerReference' => $result->customerReference,
            'addressLineCheck' => $result->addressLineCheck,
            'postalCodeCheck' => $result->postalCodeCheck,
            'cvcCheck' => $result->cvcCheck,
        ]);

        return $result;
    }

    public function authorize(GatewayId $gatewayId, PaymentInstrument $instrument, Money $amount, ?string $clientUniqueId = null, ?BillingAddress $billingAddress = null, ?ThreeDSResult $threeDS = null, ?string $statementDescription = null, ?string $description = null): AuthorizationResult
    {
        $credential = $this->credentialRepository->findOrFail($gatewayId);
        $omnipay = $this->gatewayFactory->createForCredential($credential);

        $this->logger->log('Gateway authorize request', [
            'gatewayId' => $gatewayId->toString(),
            'gatewayName' => $credential->getGatewayName(),
            'amount' => $amount,
            'instrument' => $instrument->toPayload(),
            'clientUniqueId' => $clientUniqueId,
            'billingAddress' => $billingAddress?->toArray(),
            'threeDS' => $threeDS,
            'statementDescription' => $statementDescription,
            'description' => $description,
        ]);

        $result = $this->buildAuthorization(fn () => $omnipay->authorize([
            'money' => $amount,
            'instrument' => $instrument,
            'gateway' => $credential,
            'decrypter' => $this->decrypter,
            'referenceResolver' => $this->referenceRepository,
            'clientUniqueId' => $clientUniqueId,
            'billingAddress' => $billingAddress,
            'threeDS' => $threeDS,
            'statementDescription' => $statementDescription,
            'description' => $description,
        ])->send());

        $this->logger->log('Gateway authorize response', [
            'clientUniqueId' => $clientUniqueId,
            'success' => $result->success,
            'reference' => $result->reference,
            'message' => $result->message,
            'requiresAction' => $result->isRequiresAction(),
            'challenge' => $result->challenge,
            'addressLineCheck' => $result->addressLineCheck,
            'postalCodeCheck' => $result->postalCodeCheck,
            'cvcCheck' => $result->cvcCheck,
        ]);

        return $result;
    }

    public function authorizeStoredCredential(
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
    ): AuthorizationResult {
        $credential = $this->credentialRepository->findOrFail($gatewayId);
        $omnipay = $this->gatewayFactory->createForCredential($credential);

        $this->logger->log('Gateway authorizeStoredCredential request', [
            'gatewayId' => $gatewayId->toString(),
            'gatewayName' => $credential->getGatewayName(),
            'amount' => $amount,
            'instrument' => $instrument->toPayload(),
            'clientUniqueId' => $clientUniqueId,
            'billingAddress' => $billingAddress?->toArray(),
            'threeDS' => $threeDS,
            'statementDescription' => $statementDescription,
            'description' => $description,
            'initiation' => $initiation->value,
            'genesisReference' => $genesisReference,
        ]);

        // The same Omnipay `authorize` request the ordinary path uses. The series
        // facts ride in the parameter bag rather than in a second request class,
        // because for every acquirer here this is the same endpoint with extra
        // fields — Nuvei's /payment either carries the rebilling block or does not.
        // An adapter that ignores them behaves exactly as it did before, which is
        // why this is additive rather than a new code path per provider.
        $result = $this->buildAuthorization(fn () => $omnipay->authorize([
            'money' => $amount,
            'instrument' => $instrument,
            'gateway' => $credential,
            'decrypter' => $this->decrypter,
            'referenceResolver' => $this->referenceRepository,
            'clientUniqueId' => $clientUniqueId,
            'billingAddress' => $billingAddress,
            'threeDS' => $threeDS,
            'statementDescription' => $statementDescription,
            'description' => $description,
            'initiation' => $initiation,
            'storedCredentialReference' => $genesisReference,
            'inStoredCredentialSeries' => true,
        ])->send());

        $this->logger->log('Gateway authorizeStoredCredential response', [
            'clientUniqueId' => $clientUniqueId,
            'success' => $result->success,
            'reference' => $result->reference,
            'message' => $result->message,
            'requiresAction' => $result->isRequiresAction(),
            'challenge' => $result->challenge,
        ]);

        return $result;
    }

    public function charge(GatewayId $gatewayId, PaymentInstrument $instrument, Money $amount, ?string $clientUniqueId = null, ?BillingAddress $billingAddress = null, ?ThreeDSResult $threeDS = null, ?string $statementDescription = null, ?string $description = null): AuthorizationResult
    {
        $credential = $this->credentialRepository->findOrFail($gatewayId);
        $omnipay = $this->gatewayFactory->createForCredential($credential);

        $this->logger->log('Gateway charge request', [
            'gatewayId' => $gatewayId->toString(),
            'gatewayName' => $credential->getGatewayName(),
            'amount' => $amount,
            'instrument' => $instrument->toPayload(),
            'clientUniqueId' => $clientUniqueId,
            'billingAddress' => $billingAddress?->toArray(),
            'threeDS' => $threeDS,
            'statementDescription' => $statementDescription,
            'description' => $description,
        ]);

        $result = $this->buildAuthorization(fn () => $omnipay->purchase([
            'money' => $amount,
            'instrument' => $instrument,
            'gateway' => $credential,
            'decrypter' => $this->decrypter,
            'referenceResolver' => $this->referenceRepository,
            'clientUniqueId' => $clientUniqueId,
            'billingAddress' => $billingAddress,
            'threeDS' => $threeDS,
            'statementDescription' => $statementDescription,
            'description' => $description,
        ])->send());

        $this->logger->log('Gateway charge response', [
            'clientUniqueId' => $clientUniqueId,
            'success' => $result->success,
            'reference' => $result->reference,
            'message' => $result->message,
            'requiresAction' => $result->isRequiresAction(),
            'challenge' => $result->challenge,
            'addressLineCheck' => $result->addressLineCheck,
            'postalCodeCheck' => $result->postalCodeCheck,
            'cvcCheck' => $result->cvcCheck,
        ]);

        return $result;
    }

    public function cancel(GatewayId $gatewayId, string $paymentIntentId, ?string $clientUniqueId = null): GatewayResult
    {
        $transactionReference = $this->transactionRepository->findForPaymentIntent($paymentIntentId);

        if ($transactionReference === null) {
            return GatewayResult::failed("Transaction reference for payment intent '$paymentIntentId' not found");
        }

        $credential = $this->credentialRepository->findOrFail($gatewayId);
        $omnipay = $this->gatewayFactory->createForCredential($credential);

        $this->logger->log('Gateway cancel request', [
            'gatewayId' => $gatewayId->toString(),
            'gatewayName' => $credential->getGatewayName(),
            'paymentIntentId' => $paymentIntentId,
            'transactionReference' => $transactionReference,
            'clientUniqueId' => $clientUniqueId,
        ]);

        $result = $this->buildOutcome(fn () => $omnipay->void([
            'transactionReference' => $transactionReference,
            'clientUniqueId' => $clientUniqueId,
        ])->send());

        $this->logger->log('Gateway cancel response', [
            'clientUniqueId' => $clientUniqueId,
            'success' => $result->success,
            'reference' => $result->reference,
            'message' => $result->message,
        ]);

        return $result;
    }

    public function capture(GatewayId $gatewayId, string $paymentIntentId, Money $amount, ?string $clientUniqueId = null, ?Money $authorizedAmount = null, ?PaymentInstrument $instrument = null): GatewayResult
    {
        $credential = $this->credentialRepository->findOrFail($gatewayId);
        $omnipay = $this->gatewayFactory->createForCredential($credential);
        $transactionReference = $this->transactionRepository->findForPaymentIntent($paymentIntentId);
        $transactionReference === null && throw new RuntimeException("Transaction reference for payment intent '$paymentIntentId' not found");

        $this->logger->log('Gateway capture request', [
            'gatewayId' => $gatewayId->toString(),
            'gatewayName' => $credential->getGatewayName(),
            'paymentIntentId' => $paymentIntentId,
            'transactionReference' => $transactionReference,
            'amount' => $amount,
            'clientUniqueId' => $clientUniqueId,
            'authorizedAmount' => $authorizedAmount,
            'instrument' => $instrument?->toPayload(),
        ]);

        $result = $this->buildOutcome(fn () => $omnipay->capture([
            'transactionReference' => $transactionReference,
            'money' => $amount,
            'clientUniqueId' => $clientUniqueId,
            // Consumed only by gateways without native partial capture
            // (ConnexPay voids the auth and runs a fresh sale with the
            // original instrument); others have no setters and ignore them.
            'authorizedAmount' => $authorizedAmount,
            'instrument' => $instrument,
            'gateway' => $credential,
            'decrypter' => $this->decrypter,
            'referenceResolver' => $this->referenceRepository,
        ])->send());

        $this->logger->log('Gateway capture response', [
            'clientUniqueId' => $clientUniqueId,
            'success' => $result->success,
            'reference' => $result->reference,
            'message' => $result->message,
        ]);

        return $result;
    }

    public function refund(GatewayId $gatewayId, string $paymentIntentId, Money $amount, ?string $clientUniqueId = null, ?PaymentInstrument $retryInstrument = null): GatewayResult
    {
        $credential = $this->credentialRepository->findOrFail($gatewayId);
        $omnipay = $this->gatewayFactory->createForCredential($credential);
        $transactionReference = $this->transactionRepository->findForPaymentIntent($paymentIntentId);
        $transactionReference === null && throw new RuntimeException("Transaction reference for payment intent '$paymentIntentId' not found");

        // Step 1: standard refund against the original sale. Returns funds
        // to the card used in the charge.
        $this->logger->log('Gateway refund request', [
            'gatewayId' => $gatewayId->toString(),
            'gatewayName' => $credential->getGatewayName(),
            'paymentIntentId' => $paymentIntentId,
            'transactionReference' => $transactionReference,
            'amount' => $amount,
            'clientUniqueId' => $clientUniqueId,
            'retryInstrument' => $retryInstrument?->toPayload(),
        ]);

        $standard = $this->buildOutcome(fn () => $omnipay->refund([
            'money' => $amount,
            'transactionReference' => $transactionReference,
            'clientUniqueId' => $clientUniqueId,
            'gateway' => $credential,
            'decrypter' => $this->decrypter,
            'referenceResolver' => $this->referenceRepository,
        ])->send());

        $this->logger->log('Gateway refund response', [
            'clientUniqueId' => $clientUniqueId,
            'success' => $standard->success,
            'reference' => $standard->reference,
            'message' => $standard->message,
        ]);

        if ($standard->success || $retryInstrument === null) {
            return $standard;
        }

        // Step 2: original card declined the refund and the merchant
        // supplied an alternative instrument. Per-gateway impls expose
        // `retryRefund` and pick the right native primitive:
        //   - ConnexPay: Return with ReturnRetryCard payload
        //   - Nuvei: Payout (Visa OCT / Mastercard MoneySend)
        //   - Stripe / others: no native primitive — `retryRefund` not
        //     implemented, the call falls through buildOutcome's catch
        //     and surfaces as a failed GatewayResult.
        $this->logger->log('Gateway retryRefund request', [
            'gatewayId' => $gatewayId->toString(),
            'gatewayName' => $credential->getGatewayName(),
            'paymentIntentId' => $paymentIntentId,
            'transactionReference' => $transactionReference,
            'amount' => $amount,
            'clientUniqueId' => $clientUniqueId,
            'retryInstrument' => $retryInstrument->toPayload(),
            'standardRefundMessage' => $standard->message,
        ]);

        $retried = $this->buildOutcome(fn () => $omnipay->retryRefund([
            'money' => $amount,
            'transactionReference' => $transactionReference,
            'clientUniqueId' => $clientUniqueId,
            'instrument' => $retryInstrument,
            'gateway' => $credential,
            'decrypter' => $this->decrypter,
            'referenceResolver' => $this->referenceRepository,
        ])->send());

        $this->logger->log('Gateway retryRefund response', [
            'clientUniqueId' => $clientUniqueId,
            'success' => $retried->success,
            'reference' => $retried->reference,
            'message' => $retried->message,
        ]);

        return $retried;
    }

    public function terminateVirtualCard(GatewayId $gatewayId, string $cardGuid): GatewayResult
    {
        $credential = $this->credentialRepository->findOrFail($gatewayId);
        $omnipay = $this->gatewayFactory->createForCredential($credential);

        $this->logger->log('Gateway terminateVirtualCard request', [
            'gatewayId' => $gatewayId->toString(),
            'gatewayName' => $credential->getGatewayName(),
            'cardGuid' => $cardGuid,
        ]);

        $result = $this->buildOutcome(fn () => $omnipay->terminateVirtualCard([
            'transactionReference' => $cardGuid,
        ])->send());

        $this->logger->log('Gateway terminateVirtualCard response', [
            'cardGuid' => $cardGuid,
            'success' => $result->success,
            'reference' => $result->reference,
            'message' => $result->message,
        ]);

        return $result;
    }

    public function updateVirtualCard(
        GatewayId $gatewayId,
        string $cardGuid,
        Money $amountLimit,
        CardSpendCategory $spendCategory,
    ): VirtualCardResult {
        $credential = $this->credentialRepository->findOrFail($gatewayId);
        $omnipay = $this->gatewayFactory->createForCredential($credential);

        $this->logger->log('Gateway updateVirtualCard request', [
            'gatewayId' => $gatewayId->toString(),
            'gatewayName' => $credential->getGatewayName(),
            'cardGuid' => $cardGuid,
            'amountLimit' => $amountLimit,
            'spendCategory' => $spendCategory,
        ]);

        try {
            $response = $omnipay->updateVirtualCard([
                'transactionReference' => $cardGuid,
                'money' => $amountLimit,
                'spendCategory' => $spendCategory->value,
            ])->send();

            if ($response instanceof VirtualCardResponseInterface) {
                $result = $response->toVirtualCardResult();
            } elseif ($response->isSuccessful()) {
                $result = VirtualCardResult::succeeded($response->getTransactionReference() ?? $cardGuid);
            } else {
                $result = VirtualCardResult::failed($response->getMessage() ?? 'Virtual card update failed.');
            }
        } catch (UnsupportedByGateway $e) {
            throw $e;
        } catch (Throwable $e) {
            $result = VirtualCardResult::failed($e->getMessage());
        }

        $this->logger->log('Gateway updateVirtualCard response', [
            'cardGuid' => $cardGuid,
            'success' => $result->success,
            'message' => $result->message,
            'status' => $result->status,
        ]);

        return $result;
    }

    public function issueVirtualCard(
        GatewayId $gatewayId,
        string $paymentIntentId,
        Money $amountLimit,
        CardSpendCategory $spendCategory,
        ?string $firstName = null,
        ?string $lastName = null,
        ?CardBrand $cardBrand = null,
        ?string $clientUniqueId = null,
    ): VirtualCardResult {
        $credential = $this->credentialRepository->findOrFail($gatewayId);
        $omnipay = $this->gatewayFactory->createForCredential($credential);
        $transactionReference = $this->transactionRepository->findForPaymentIntent($paymentIntentId);
        $transactionReference === null && throw new RuntimeException("Transaction reference for payment intent '$paymentIntentId' not found");

        $this->logger->log('Gateway issueVirtualCard request', [
            'gatewayId' => $gatewayId->toString(),
            'gatewayName' => $credential->getGatewayName(),
            'paymentIntentId' => $paymentIntentId,
            'transactionReference' => $transactionReference,
            'amountLimit' => $amountLimit,
            'spendCategory' => $spendCategory,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'cardBrand' => $cardBrand,
            'clientUniqueId' => $clientUniqueId,
        ]);

        try {
            // The incoming transaction code persisted at sale / capture time
            // (TransactionMetadataProvider). Passing it spares the gateway a
            // Search/Sales round-trip whose guid filters ConnexPay silently
            // ignores.
            $metadata = $this->transactionRepository->findMetadataForPaymentIntent($paymentIntentId);

            $response = $omnipay->issueVirtualCard([
                'money' => $amountLimit,
                'transactionReference' => $transactionReference,
                'spendCategory' => $spendCategory->value,
                'firstName' => $firstName,
                'lastName' => $lastName,
                'cardBrand' => $cardBrand,
                'clientUniqueId' => $clientUniqueId,
                'incomingTransactionCode' => $metadata['incoming_transaction_code'] ?? null,
            ])->send();

            if ($response instanceof VirtualCardResponseInterface) {
                $result = $response->toVirtualCardResult();
            } elseif ($response->isSuccessful()) {
                $result = VirtualCardResult::succeeded($response->getTransactionReference());
            } else {
                $result = VirtualCardResult::failed($response->getMessage() ?? 'Virtual card issuance failed.');
            }
        } catch (UnsupportedByGateway $e) {
            throw $e;
        } catch (Throwable $e) {
            $result = VirtualCardResult::failed($e->getMessage());
        }

        $this->logger->log('Gateway issueVirtualCard response', [
            'clientUniqueId' => $clientUniqueId,
            'success' => $result->success,
            'cardGuid' => $result->cardGuid,
            'cardNumber' => $result->cardNumber,
            'cvv' => $result->cvv,
            'expirationDate' => $result->expirationDate,
            'status' => $result->status,
            'message' => $result->message,
        ]);

        return $result;
    }

    /**
     * Builder for ops with no extra signals (capture / refund / cancel /
     * terminate). Failure can come from either an unsuccessful response or
     * a thrown exception; both collapse to {@see GatewayResult::failed} —
     * except {@see UnsupportedByGateway}, which is a wiring error rather than
     * a payment outcome and is rethrown so it cannot be mistaken for a decline.
     *
     * @param callable(): ResponseInterface $request
     */
    private function buildOutcome(callable $request): GatewayResult
    {
        try {
            $response = $request();

            if ($response->isSuccessful()) {
                return GatewayResult::succeeded($response->getTransactionReference())
                    ->withMetadata(self::extractMetadata($response))
                    ->withConvertedAmount(self::extractConvertedAmount($response));
            }

            return GatewayResult::failed($response->getMessage() ?? 'Gateway returned an unsuccessful response.');
        } catch (UnsupportedByGateway $e) {
            throw $e;
        } catch (Throwable $e) {
            return GatewayResult::failed($e->getMessage());
        }
    }

    /**
     * Builder for authorize / charge — folds {@see ChallengeProvider} (3DS,
     * hosted redirect) and {@see CardChecksProvider} (AVS / CVC) signals
     * onto an {@see AuthorizationResult}.
     *
     * @param callable(): ResponseInterface $request
     */
    private function buildAuthorization(callable $request): AuthorizationResult
    {
        try {
            $response = $request();

            if ($response instanceof ChallengeProvider && ($challenge = $response->getChallenge()) !== null) {
                return self::attachChecksToAuthorization(
                    AuthorizationResult::requiresAction($response->getTransactionReference(), $challenge),
                    $response,
                )->withMetadata(self::extractMetadata($response));
            }

            if ($response->isSuccessful()) {
                return self::attachChecksToAuthorization(
                    AuthorizationResult::succeeded($response->getTransactionReference()),
                    $response,
                )->withMetadata(self::extractMetadata($response))
                    ->withConvertedAmount(self::extractConvertedAmount($response));
            }

            return AuthorizationResult::failed($response->getMessage() ?? 'Gateway returned an unsuccessful response.');
        } catch (UnsupportedByGateway $e) {
            throw $e;
        } catch (Throwable $e) {
            return AuthorizationResult::failed($e->getMessage());
        }
    }

    /**
     * Builder for tokenize / createPaymentMethod — folds
     * {@see CustomerReferenceProvider} and {@see CardChecksProvider} signals
     * onto a {@see RegistrationResult}.
     *
     * @param callable(): ResponseInterface $request
     */
    private function buildRegistration(callable $request): RegistrationResult
    {
        try {
            $response = $request();

            if (! $response->isSuccessful()) {
                return RegistrationResult::failed($response->getMessage() ?? 'Gateway returned an unsuccessful response.');
            }

            $customerReference = $response instanceof CustomerReferenceProvider
                ? $response->getCustomerReference()
                : null;

            return self::attachChecksToRegistration(
                RegistrationResult::succeeded($response->getTransactionReference())
                    ->withCustomerReference($customerReference),
                $response,
            );
        } catch (UnsupportedByGateway $e) {
            throw $e;
        } catch (Throwable $e) {
            return RegistrationResult::failed($e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function extractMetadata(ResponseInterface $response): array
    {
        return $response instanceof TransactionMetadataProvider
            ? $response->getTransactionMetadata()
            : [];
    }

    private static function extractConvertedAmount(ResponseInterface $response): ?Money
    {
        return $response instanceof ConvertedAmountProvider
            ? $response->getConvertedAmount()
            : null;
    }

    private static function attachChecksToAuthorization(AuthorizationResult $result, ResponseInterface $response): AuthorizationResult
    {
        if (! $response instanceof CardChecksProvider) {
            return $result;
        }

        return $result->withChecks(
            $response->getAddressLineCheck(),
            $response->getPostalCodeCheck(),
            $response->getCvcCheck(),
        );
    }

    private static function attachChecksToRegistration(RegistrationResult $result, ResponseInterface $response): RegistrationResult
    {
        if (! $response instanceof CardChecksProvider) {
            return $result;
        }

        return $result->withChecks(
            $response->getAddressLineCheck(),
            $response->getPostalCodeCheck(),
            $response->getCvcCheck(),
        );
    }
}
