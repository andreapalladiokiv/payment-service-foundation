<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Routing;

use Override;
use Techork\PaymentService\Gateway\Command\PlacementCommand;
use Techork\PaymentService\Gateway\Command\VaultCommand;
use Techork\PaymentService\Gateway\Command\RebillingCommand;
use Techork\PaymentService\Gateway\Command\CancelCommand;
use Techork\PaymentService\Gateway\Command\CaptureCommand;
use Techork\PaymentService\Gateway\Command\RefundCommand;
use Techork\PaymentService\Gateway\Command\RegisterCustomerCommand;
use Techork\PaymentService\Gateway\Contract\GatewayCredentialRepository;
use Techork\PaymentService\Gateway\Contract\AuthorizationResult;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Gateway\Contract\RegistrationResult;
use Techork\PaymentService\Gateway\Decorator\FailureBoundary;
use Techork\PaymentService\Gateway\Decorator\LoggingGateway;
use Techork\PaymentService\Gateway\GatewayFactory;
use Techork\PaymentService\Gateway\Logger\GatewayLoggerInterface;
use Techork\PaymentService\Gateway\Logger\NullGatewayLogger;
use Techork\PaymentService\Gateway\Role\AcquiringGateway;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * A gateway bound to one gateway id, standing in for whichever provider that id resolves to.
 *
 * A proxy in the plain sense: it implements what its subject implements and adds nothing to the
 * call. What it removes is the gateway id from every signature — a caller already knows which
 * gateway it is working with when it is built (`CaptureAdapter` has held one in its
 * constructor all along), so passing it again per call only forced proxy and provider to speak
 * different languages.
 *
 * Resolution is lazy and cached: a port built for a payment that never places one costs no
 * credential lookup, and one that places several looks up once.
 *
 * The decorators are composed HERE, around the resolved provider, rather than around this object.
 * `findOrFail` on an unknown credential is a wiring error and has to propagate as itself; a
 * {@see FailureBoundary} wrapped outside the resolve would fold it into a failed result and record
 * a decline for a gateway nobody could even find.
 *
 * They stay here rather than moving into {@see GatewayFactory} because there are two stacks. A
 * driver implements both composites, but a decorator implements one, so a factory handing out a
 * ready-decorated provider would have nothing to return: each proxy wraps the half it serves.
 */
final class RoutedGateway implements AcquiringGateway
{
    private ?AcquiringGateway $subject = null;

    public function __construct(
        private readonly GatewayId $gatewayId,
        private readonly GatewayCredentialRepository $credentials,
        private readonly GatewayFactory $gateways,
        private readonly GatewayLoggerInterface $logger = new NullGatewayLogger,
    ) {}

    #[Override]
    public function authorize(PlacementCommand $command): AuthorizationResult
    {
        return $this->subject()->authorize($command);
    }

    #[Override]
    public function charge(PlacementCommand $command): AuthorizationResult
    {
        return $this->subject()->charge($command);
    }

    #[Override]
    public function authorizeRebilling(RebillingCommand $command): AuthorizationResult
    {
        return $this->subject()->authorizeRebilling($command);
    }

    #[Override]
    public function tokenize(VaultCommand $command): RegistrationResult
    {
        return $this->subject()->tokenize($command);
    }

    #[Override]
    public function registerPaymentMethod(VaultCommand $command): RegistrationResult
    {
        return $this->subject()->registerPaymentMethod($command);
    }

    #[Override]
    public function registerCustomer(RegisterCustomerCommand $command): RegistrationResult
    {
        return $this->subject()->registerCustomer($command);
    }

    #[Override]
    public function capture(CaptureCommand $command): GatewayResult
    {
        return $this->subject()->capture($command);
    }

    #[Override]
    public function cancel(CancelCommand $command): GatewayResult
    {
        return $this->subject()->cancel($command);
    }

    #[Override]
    public function refund(RefundCommand $command): GatewayResult
    {
        return $this->subject()->refund($command);
    }

    #[Override]
    public function retryRefund(RefundCommand $command): GatewayResult
    {
        return $this->subject()->retryRefund($command);
    }

    private function subject(): AcquiringGateway
    {
        if ($this->subject !== null) {
            return $this->subject;
        }

        $credential = $this->credentials->findOrFail($this->gatewayId);

        return $this->subject = new LoggingGateway(
            new FailureBoundary($this->gateways->createForCredential($credential)),
            $this->logger,
            $credential->getGatewayName(),
        );
    }
}
