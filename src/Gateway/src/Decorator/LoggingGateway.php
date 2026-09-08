<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Decorator;

use Override;
use Techork\PaymentService\Gateway\Command\PlacementCommand;
use Techork\PaymentService\Gateway\Command\VaultCommand;
use Techork\PaymentService\Gateway\Command\RebillingCommand;
use Techork\PaymentService\Gateway\Command\CancelCommand;
use Techork\PaymentService\Gateway\Command\CaptureCommand;
use Techork\PaymentService\Gateway\Command\RefundCommand;
use Techork\PaymentService\Gateway\Command\RegisterCustomerCommand;
use Techork\PaymentService\Gateway\Contract\AuthorizationResult;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Gateway\Contract\RegistrationResult;
use Techork\PaymentService\Gateway\Logger\GatewayLoggerInterface;
use Techork\PaymentService\Gateway\Role\AcquiringGateway;

/**
 * Logs the request and the answer around whatever it wraps.
 *
 * Both sides are derived — the request from {@see CaptureCommand::toLogContext()}, the answer
 * from {@see GatewayResult::toLogContext()} — rather than written out per operation. The router
 * had twenty-six hand-built arrays restating parameter lists it had already written twice, and
 * they drifted: `issueVirtualCard` logged a `CardSpendCategory` object where `updateVirtualCard`
 * logged its `->value`. Nothing here can drift from the objects it describes.
 *
 * Outside {@see FailureBoundary}, so what it records is the answer a caller receives, including
 * a provider error already folded into a failed result. A marked refusal propagates without a
 * response line, which is accurate: nothing answered.
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
        return $this->aroundAuthorization('authorize', $command->toLogContext(), $command->clientUniqueId,
            fn (): AuthorizationResult => $this->inner->authorize($command));
    }

    #[Override]
    public function charge(PlacementCommand $command): AuthorizationResult
    {
        return $this->aroundAuthorization('charge', $command->toLogContext(), $command->clientUniqueId,
            fn (): AuthorizationResult => $this->inner->charge($command));
    }

    #[Override]
    public function authorizeRebilling(RebillingCommand $command): AuthorizationResult
    {
        return $this->aroundAuthorization('authorizeRebilling', $command->toLogContext(), $command->clientUniqueId,
            fn (): AuthorizationResult => $this->inner->authorizeRebilling($command));
    }

    #[Override]
    public function tokenize(VaultCommand $command): RegistrationResult
    {
        return $this->aroundRegistration('tokenize', $command->toLogContext(), $command->clientUniqueId,
            fn (): RegistrationResult => $this->inner->tokenize($command));
    }

    #[Override]
    public function registerPaymentMethod(VaultCommand $command): RegistrationResult
    {
        return $this->aroundRegistration('registerPaymentMethod', $command->toLogContext(), $command->clientUniqueId,
            fn (): RegistrationResult => $this->inner->registerPaymentMethod($command));
    }

    #[Override]
    public function registerCustomer(RegisterCustomerCommand $command): RegistrationResult
    {
        // Keyed on our customer id rather than on a `clientUniqueId`, which this operation has
        // none of: registering a customer is not a payment, so there is no per-attempt id to
        // correlate on and the customer is the only thing the two log lines share.
        return $this->aroundRegistration('registerCustomer', $command->toLogContext(), $command->customerId->toString(),
            fn (): RegistrationResult => $this->inner->registerCustomer($command));
    }

    #[Override]
    public function capture(CaptureCommand $command): GatewayResult
    {
        return $this->around('capture', $command->toLogContext(), $command->clientUniqueId,
            fn (): GatewayResult => $this->inner->capture($command));
    }

    #[Override]
    public function cancel(CancelCommand $command): GatewayResult
    {
        return $this->around('cancel', $command->toLogContext(), $command->clientUniqueId,
            fn (): GatewayResult => $this->inner->cancel($command));
    }

    #[Override]
    public function refund(RefundCommand $command): GatewayResult
    {
        return $this->around('refund', $command->toLogContext(), $command->clientUniqueId,
            fn (): GatewayResult => $this->inner->refund($command));
    }

    #[Override]
    public function retryRefund(RefundCommand $command): GatewayResult
    {
        return $this->around('retryRefund', $command->toLogContext(), $command->clientUniqueId,
            fn (): GatewayResult => $this->inner->retryRefund($command));
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
            ...$result->toLogContext(),
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
            ...$result->toLogContext(),
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
            ...$result->toLogContext(),
        ]);

        return $result;
    }
}
