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
use Techork\PaymentService\Gateway\Contract\AuthorizationResult;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Gateway\Contract\RegistrationResult;
use Techork\PaymentService\Gateway\Exception\UnsupportedByGateway;
use Techork\PaymentService\Gateway\Role\AcquiringGateway;
use Throwable;

/**
 * Where a thrown provider error becomes a value.
 *
 * Everything the acquirer can go wrong with — a timeout, a malformed response, an SDK
 * exception — folds into a failed result the caller can record. Everything carrying
 * {@see UnsupportedByGateway} does not: that marker means we asked a gateway for something its
 * product does not have, which is a wiring error, and recording it as a failed result would
 * tell operators the issuer declined a payment no issuer ever saw.
 *
 * One class where the gateway stack had this
 * `try`/`catch` four times — once per result shape, plus an inline copy in `issueVirtualCard`.
 * It sits INSIDE the routing proxy on purpose: `findOrFail` on a missing credential must
 * propagate as itself, and a boundary wrapped around the resolve would swallow it into the same
 * lie the marker exists to prevent.
 */
final readonly class FailureBoundary implements AcquiringGateway
{
    public function __construct(private AcquiringGateway $inner) {}

    #[Override]
    public function authorize(PlacementCommand $command): AuthorizationResult
    {
        return $this->boundAuthorization(fn (): AuthorizationResult => $this->inner->authorize($command));
    }

    #[Override]
    public function charge(PlacementCommand $command): AuthorizationResult
    {
        return $this->boundAuthorization(fn (): AuthorizationResult => $this->inner->charge($command));
    }

    #[Override]
    public function authorizeRebilling(RebillingCommand $command): AuthorizationResult
    {
        return $this->boundAuthorization(fn (): AuthorizationResult => $this->inner->authorizeRebilling($command));
    }

    #[Override]
    public function tokenize(VaultCommand $command): RegistrationResult
    {
        return $this->boundRegistration(fn (): RegistrationResult => $this->inner->tokenize($command));
    }

    #[Override]
    public function registerPaymentMethod(VaultCommand $command): RegistrationResult
    {
        return $this->boundRegistration(fn (): RegistrationResult => $this->inner->registerPaymentMethod($command));
    }

    #[Override]
    public function capture(CaptureCommand $command): GatewayResult
    {
        return $this->bound(fn (): GatewayResult => $this->inner->capture($command));
    }

    #[Override]
    public function cancel(CancelCommand $command): GatewayResult
    {
        return $this->bound(fn (): GatewayResult => $this->inner->cancel($command));
    }

    #[Override]
    public function refund(RefundCommand $command): GatewayResult
    {
        return $this->bound(fn (): GatewayResult => $this->inner->refund($command));
    }

    #[Override]
    public function retryRefund(RefundCommand $command): GatewayResult
    {
        return $this->bound(fn (): GatewayResult => $this->inner->retryRefund($command));
    }

    /**
     * Shared by every operation whose answer is a bare {@see GatewayResult}. Operations with a
     * richer result get their own, because the failure they fold into is their own type — that is
     * the whole reason this cannot be one generic method.
     *
     * @param callable(): GatewayResult $call
     *
     * @throws UnsupportedByGateway
     */
    private function bound(callable $call): GatewayResult
    {
        try {
            return $call();
        } catch (UnsupportedByGateway $e) {
            throw $e;
        } catch (Throwable $e) {
            return GatewayResult::failed($e->getMessage());
        }
    }

    /**
     * The same boundary for the richer result. Separate because the failure it folds into is a
     * different type — which is the reason this cannot be one generic method, and the reason each
     * result shape gets its own.
     *
     * @param callable(): AuthorizationResult $call
     *
     * @throws UnsupportedByGateway
     */
    private function boundAuthorization(callable $call): AuthorizationResult
    {
        try {
            return $call();
        } catch (UnsupportedByGateway $e) {
            throw $e;
        } catch (Throwable $e) {
            return AuthorizationResult::failed($e->getMessage());
        }
    }

    /**
     * @param callable(): RegistrationResult $call
     *
     * @throws UnsupportedByGateway
     */
    private function boundRegistration(callable $call): RegistrationResult
    {
        try {
            return $call();
        } catch (UnsupportedByGateway $e) {
            throw $e;
        } catch (Throwable $e) {
            return RegistrationResult::failed($e->getMessage());
        }
    }
}
