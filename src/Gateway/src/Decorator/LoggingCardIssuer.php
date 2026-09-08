<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Decorator;

use Override;
use Techork\PaymentService\Gateway\Command\IssueCardCommand;
use Techork\PaymentService\Gateway\Command\TerminateCardCommand;
use Techork\PaymentService\Gateway\Command\UpdateCardCommand;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Gateway\Contract\VirtualCardResult;
use Techork\PaymentService\Gateway\Logger\GatewayLoggerInterface;
use Techork\PaymentService\Gateway\Role\CardIssuer;

/**
 * {@see LoggingGateway} for the issuing stack. Both sides derived from the command and the result,
 * for the reason the payment one gives: the hand-written arrays these replace had already drifted,
 * logging a spend category as an object on one operation and as its `->value` on the next.
 */
final readonly class LoggingCardIssuer implements CardIssuer
{
    public function __construct(
        private CardIssuer $inner,
        private GatewayLoggerInterface $logger,
        private string $gatewayName,
    ) {}

    #[Override]
    public function issueVirtualCard(IssueCardCommand $command): VirtualCardResult
    {
        $this->request('issueVirtualCard', $command->toLogContext());
        $result = $this->inner->issueVirtualCard($command);
        $this->response('issueVirtualCard', $command->clientUniqueId, $result->toLogContext());

        return $result;
    }

    #[Override]
    public function updateVirtualCard(UpdateCardCommand $command): VirtualCardResult
    {
        $this->request('updateVirtualCard', $command->toLogContext());
        $result = $this->inner->updateVirtualCard($command);
        $this->response('updateVirtualCard', null, $result->toLogContext());

        return $result;
    }

    #[Override]
    public function terminateVirtualCard(TerminateCardCommand $command): GatewayResult
    {
        $this->request('terminateVirtualCard', $command->toLogContext());
        $result = $this->inner->terminateVirtualCard($command);
        $this->response('terminateVirtualCard', null, $result->toLogContext());

        return $result;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function request(string $operation, array $context): void
    {
        $this->logger->log("Gateway {$operation} request", ['gatewayName' => $this->gatewayName, ...$context]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function response(string $operation, ?string $clientUniqueId, array $context): void
    {
        $this->logger->log("Gateway {$operation} response", ['clientUniqueId' => $clientUniqueId, ...$context]);
    }
}
