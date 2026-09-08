<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Routing;

use Override;
use Techork\PaymentService\Gateway\Command\IssueCardCommand;
use Techork\PaymentService\Gateway\Command\TerminateCardCommand;
use Techork\PaymentService\Gateway\Command\UpdateCardCommand;
use Techork\PaymentService\Gateway\Contract\GatewayCredentialRepository;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Gateway\Contract\VirtualCardResult;
use Techork\PaymentService\Gateway\Decorator\CardIssuerFailureBoundary;
use Techork\PaymentService\Gateway\Decorator\LoggingCardIssuer;
use Techork\PaymentService\Gateway\GatewayFactory;
use Techork\PaymentService\Gateway\Logger\GatewayLoggerInterface;
use Techork\PaymentService\Gateway\Logger\NullGatewayLogger;
use Techork\PaymentService\Gateway\Role\CardIssuer;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * {@see RoutedGateway} for the issuing stack: the same proxy bound to one gateway id, composing the
 * same two decorators inside the resolve so a missing credential propagates as itself.
 */
final class RoutedCardIssuer implements CardIssuer
{
    private ?CardIssuer $subject = null;

    public function __construct(
        private readonly GatewayId $gatewayId,
        private readonly GatewayCredentialRepository $credentials,
        private readonly GatewayFactory $gateways,
        private readonly GatewayLoggerInterface $logger = new NullGatewayLogger,
    ) {}

    #[Override]
    public function issueVirtualCard(IssueCardCommand $command): VirtualCardResult
    {
        return $this->subject()->issueVirtualCard($command);
    }

    #[Override]
    public function updateVirtualCard(UpdateCardCommand $command): VirtualCardResult
    {
        return $this->subject()->updateVirtualCard($command);
    }

    #[Override]
    public function terminateVirtualCard(TerminateCardCommand $command): GatewayResult
    {
        return $this->subject()->terminateVirtualCard($command);
    }

    private function subject(): CardIssuer
    {
        if ($this->subject !== null) {
            return $this->subject;
        }

        $credential = $this->credentials->findOrFail($this->gatewayId);

        return $this->subject = new LoggingCardIssuer(
            new CardIssuerFailureBoundary($this->gateways->createForCredential($credential)),
            $this->logger,
            $credential->getGatewayName(),
        );
    }
}
