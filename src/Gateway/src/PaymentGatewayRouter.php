<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway;

use Money\Money;
use Omnipay\Common\Message\ResponseInterface;
use Override;
use RuntimeException;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CustomerIdentity;
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
use Techork\PaymentService\Gateway\Contract\RegistersCustomers;
use Techork\PaymentService\Gateway\Exception\UnsupportedOperation;
use Techork\PaymentService\Gateway\Exception\RegistrationNeedsCustomer;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Throwable;

final readonly class PaymentGatewayRouter implements PaymentGatewayInterface
{
    private const string UNNAMED_SUCCESS = 'The gateway reported success without naming a transaction reference.';

    public function __construct(
        private GatewayFactory $gatewayFactory,
        private DecryptInterface $decrypter,
        private GatewayCredentialRepository $credentialRepository,
        private GatewayInstrumentRepository $referenceRepository,
        private GatewayTransactionRepository $transactionRepository,
        private GatewayLoggerInterface $logger = new NullGatewayLogger(),
    ) {}

    #[Override]
    public function registerCustomer(GatewayId $gatewayId, string $customerId, CustomerIdentity $identity): GatewayResult
    {
        $credential = $this->credentialRepository->findOrFail($gatewayId);
        $omnipay = $this->gatewayFactory->createForCredential($credential);

        // Refused, not degraded: a provider with no customer object cannot be asked to make one,
        // and turning that into a failed result would read as the provider saying no to a customer
        // it was never told about. ConnexPay is the case — its `CustomerID` is a field on a
        // transaction, so its payment methods are attached by definition and there is nothing here
        // to create.
        $omnipay instanceof RegistersCustomers || throw UnsupportedOperation::forGateway(
            $credential->getGatewayName(),
            'registerCustomer',
            'the provider has no customer object to create.',
        );

        $this->logger->log('Gateway registerCustomer request', [
            'gatewayId' => $gatewayId->toString(),
            'gatewayName' => $credential->getGatewayName(),
            'customerId' => $customerId,
        ]);

        // Not saved here. Remembering which id a provider knows a customer under is
        // `GatewayCustomerRepository`'s, and the caller owns that pairing the same way it owns the
        // transaction references the ports persist — this only performs the call.
        $result = $this->buildOutcome(fn () => $omnipay->createCustomer([
            'customerId' => $customerId,
            'customerIdentity' => $identity,
            'gateway' => $credential,
        ])->send());

        $this->logger->log('Gateway registerCustomer response', [
            'customerId' => $customerId,
            'success' => $result->success,
            'reference' => $result->reference,
            'message' => $result->message,
        ]);

        return $result;
    }

    #[Override]
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

    #[Override]
    public function createPaymentMethod(GatewayId $gatewayId, PaymentInstrument $instrument, string $customerId, ?BillingAddress $billingAddress = null, ?string $clientUniqueId = null): RegistrationResult
    {
        $credential = $this->credentialRepository->findOrFail($gatewayId);

        // Before the gateway is even built. Registering for nobody is a wiring mistake of the
        // caller's, and reaching the provider first would turn it into something that looks like
        // the provider's answer.
        trim($customerId) === '' && throw RegistrationNeedsCustomer::forGateway($credential->getGatewayName());

        $omnipay = $this->gatewayFactory->createForCredential($credential);

        $this->logger->log('Gateway createPaymentMethod request', [
            'gatewayId' => $gatewayId->toString(),
            'gatewayName' => $credential->getGatewayName(),
            'instrument' => $instrument->toPayload(),
            'billingAddress' => $billingAddress?->toArray(),
            'clientUniqueId' => $clientUniqueId,
            'customerId' => $customerId,
        ]);

        $result = $this->buildRegistration(fn () => $omnipay->createPaymentMethod([
            'instrument' => $instrument,
            'gateway' => $credential,
            'decrypter' => $this->decrypter,
            'referenceResolver' => $this->referenceRepository,
            'billingAddress' => $billingAddress,
            'clientUniqueId' => $clientUniqueId,
            // Both adapters that can vault an instrument already read this; until now the router
            // never supplied it, so the resolution got null and the card was stored for nobody.
            'customerId' => $customerId,
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

    #[Override]
    public function authorize(GatewayId $gatewayId, PaymentInstrument $instrument, Money $amount, ?string $clientUniqueId = null, ?BillingAddress $billingAddress = null, ?ThreeDSResult $threeDS = null, ?string $statementDescription = null, ?string $description = null, PaymentInitiation $initiation = PaymentInitiation::CardholderInitiated, ?string $customerId = null): AuthorizationResult
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
            'initiation' => $initiation->value,
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
            'initiation' => $initiation,
            'customerId' => $customerId,
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

    #[Override]
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
        ?string $customerId = null,
    ): AuthorizationResult {
        $credential = $this->credentialRepository->findOrFail($gatewayId);
        $omnipay = $this->gatewayFactory->createForCredential($credential);

        $this->logger->log('Gateway authorizeRebilling request', [
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
            'customerId' => $customerId,
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
            'rebillingReference' => $genesisReference,
            'rebilling' => true,
            // Both gateways that can renew read this off their `authorize()`, which is what this
            // routes through. Nuvei cannot renew without it: a subsequent rebilling payment uses
            // a `userPaymentOptionId`, and that only exists under a `userTokenId`.
            'customerId' => $customerId,
        ])->send());

        $this->logger->log('Gateway authorizeRebilling response', [
            'clientUniqueId' => $clientUniqueId,
            'success' => $result->success,
            'reference' => $result->reference,
            'message' => $result->message,
            'requiresAction' => $result->isRequiresAction(),
            'challenge' => $result->challenge,
        ]);

        return $result;
    }

    #[Override]
    public function charge(GatewayId $gatewayId, PaymentInstrument $instrument, Money $amount, ?string $clientUniqueId = null, ?BillingAddress $billingAddress = null, ?ThreeDSResult $threeDS = null, ?string $statementDescription = null, ?string $description = null, PaymentInitiation $initiation = PaymentInitiation::CardholderInitiated, ?string $customerId = null): AuthorizationResult
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
            'initiation' => $initiation->value,
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
            'initiation' => $initiation,
            'customerId' => $customerId,
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

    #[Override]
    public function cancel(GatewayId $gatewayId, string $transactionReference, ?string $clientUniqueId = null): GatewayResult
    {
        $credential = $this->credentialRepository->findOrFail($gatewayId);
        $omnipay = $this->gatewayFactory->createForCredential($credential);

        $this->logger->log('Gateway cancel request', [
            'gatewayId' => $gatewayId->toString(),
            'gatewayName' => $credential->getGatewayName(),
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

    #[Override]
    public function capture(GatewayId $gatewayId, string $transactionReference, Money $amount, ?string $clientUniqueId = null, ?Money $authorizedAmount = null, ?PaymentInstrument $instrument = null, ?string $customerId = null): GatewayResult
    {
        $credential = $this->credentialRepository->findOrFail($gatewayId);
        $omnipay = $this->gatewayFactory->createForCredential($credential);
        $this->logger->log('Gateway capture request', [
            'gatewayId' => $gatewayId->toString(),
            'gatewayName' => $credential->getGatewayName(),
            'transactionReference' => $transactionReference,
            'amount' => $amount,
            'clientUniqueId' => $clientUniqueId,
            'authorizedAmount' => $authorizedAmount,
            'instrument' => $instrument?->toPayload(),
            'customerId' => $customerId,
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
            // On capture too, not only at the start. ConnexPay documents that a Capture's
            // `OrderNumber` overwrites the Auth's and says nothing about `CustomerID`; if it
            // behaves the same, a capture sent without one blanks what the auth recorded.
            // Gateways with no `setCustomerId()` drop it — Omnipay's `Helper::initialize()`
            // applies a key only where a matching setter exists.
            'customerId' => $customerId,
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

    #[Override]
    public function refund(GatewayId $gatewayId, string $transactionReference, Money $amount, ?string $clientUniqueId = null, ?PaymentInstrument $retryInstrument = null): GatewayResult
    {
        $credential = $this->credentialRepository->findOrFail($gatewayId);
        $omnipay = $this->gatewayFactory->createForCredential($credential);
        // Step 1: standard refund against the original sale. Returns funds
        // to the card used in the charge.
        $this->logger->log('Gateway refund request', [
            'gatewayId' => $gatewayId->toString(),
            'gatewayName' => $credential->getGatewayName(),
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

    #[Override]
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

    #[Override]
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

    #[Override]
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
                $reference = self::successReference($response);
                $result = $reference === null
                    ? VirtualCardResult::failed(self::UNNAMED_SUCCESS)
                    : VirtualCardResult::succeeded($reference);
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
     * @return GatewayResult
     * @throws UnsupportedByGateway
     */
    private function buildOutcome(callable $request): GatewayResult
    {
        try {
            $response = $request();

            if ($response->isSuccessful()) {
                $reference = self::successReference($response);

                if ($reference === null) {
                    return GatewayResult::failed(self::UNNAMED_SUCCESS);
                }

                return GatewayResult::succeeded($reference)
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
     * @return AuthorizationResult
     * @throws UnsupportedByGateway
     */
    private function buildAuthorization(callable $request): AuthorizationResult
    {
        try {
            $response = $request();

            if ($response instanceof ChallengeProvider && ($challenge = $response->getChallenge()) !== null) {
                $reference = self::successReference($response);

                if ($reference === null) {
                    return AuthorizationResult::failed(self::UNNAMED_SUCCESS);
                }

                return self::attachChecksToAuthorization(
                    AuthorizationResult::requiresAction($reference, $challenge),
                    $response,
                )->withMetadata(self::extractOpeningMetadata($response));
            }

            if ($response->isSuccessful()) {
                $reference = self::successReference($response);

                if ($reference === null) {
                    return AuthorizationResult::failed(self::UNNAMED_SUCCESS);
                }

                return self::attachChecksToAuthorization(
                    AuthorizationResult::succeeded($reference),
                    $response,
                )->withMetadata(self::extractOpeningMetadata($response))
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
     * @return RegistrationResult
     * @throws UnsupportedByGateway
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

            $reference = self::successReference($response);

            if ($reference === null) {
                return RegistrationResult::failed(self::UNNAMED_SUCCESS);
            }

            return self::attachChecksToRegistration(
                RegistrationResult::succeeded($reference)
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

    /**
     * The gateway's metadata plus `opening_transaction_reference` — the reference of
     * the transaction that OPENED the payment intent.
     *
     * Recorded because `reference` cannot answer that later: it overwrites on
     * transition, so once a capture lands the row holds the settle reference. Both
     * readings are wanted — Nuvei's settle expects the authorization's transactionId
     * and its refund expects the settle's — so they live side by side.
     *
     * Added HERE, in {@see buildAuthorization}, and nowhere else. Only the opening
     * operations pass through it; capture, cancel and refund are built by
     * {@see buildOutcome}, so it is structurally impossible for one of them to write
     * this key and bury the value with its own reference. A port adding it instead
     * would have looked equivalent and lost that guarantee.
     *
     * @return array<string, mixed>
     */
    /**
     * The reference a response reporting success must name, or null when it named none.
     *
     * A success that identifies nothing is unreachable afterwards: the reference is the only
     * handle the ports ever get, so nothing could capture, cancel or refund it. Every builder
     * below turns this into a failure rather than recording a payment no later operation can
     * address.
     *
     * This is not new behaviour. `succeeded()` and `requiresAction()` already declare a
     * `string`, so a null arrived as a TypeError, was caught by the same `Throwable` handler
     * and became a failed result carrying "must be of type string, null given" — an internal
     * artefact where the merchant expects a reason. Empty counts as absent for the same
     * reason {@see self::extractOpeningMetadata()} treats it that way.
     */
    private static function successReference(ResponseInterface $response): ?string
    {
        $reference = $response->getTransactionReference();

        return $reference === null || $reference === '' ? null : $reference;
    }

    private static function extractOpeningMetadata(ResponseInterface $response): array
    {
        $metadata = self::extractMetadata($response);
        $reference = $response->getTransactionReference();

        return $reference === null || $reference === ''
            ? $metadata
            : [...$metadata, 'opening_transaction_reference' => $reference];
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
