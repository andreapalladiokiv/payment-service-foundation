<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Decorator;

use Override;
use Techork\PaymentService\Gateway\Command\IssueCardCommand;
use Techork\PaymentService\Gateway\Command\TerminateCardCommand;
use Techork\PaymentService\Gateway\Command\UpdateCardCommand;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Gateway\Contract\VirtualCardResult;
use Techork\PaymentService\Gateway\Exception\UnsupportedByGateway;
use Techork\PaymentService\Gateway\Role\CardIssuer;
use Throwable;

/**
 * {@see FailureBoundary} for the issuing stack. The rule is identical — a provider error becomes a
 * value, a marked refusal propagates — and the class is separate because the values differ: a card
 * operation fails as a {@see VirtualCardResult}, not as a payment outcome.
 */
final readonly class CardIssuerFailureBoundary implements CardIssuer
{
    public function __construct(private CardIssuer $inner) {}

    #[Override]
    public function issueVirtualCard(IssueCardCommand $command): VirtualCardResult
    {
        return $this->boundCard(fn (): VirtualCardResult => $this->inner->issueVirtualCard($command));
    }

    #[Override]
    public function updateVirtualCard(UpdateCardCommand $command): VirtualCardResult
    {
        return $this->boundCard(fn (): VirtualCardResult => $this->inner->updateVirtualCard($command));
    }

    #[Override]
    public function terminateVirtualCard(TerminateCardCommand $command): GatewayResult
    {
        try {
            return $this->inner->terminateVirtualCard($command);
        } catch (UnsupportedByGateway $e) {
            throw $e;
        } catch (Throwable $e) {
            return GatewayResult::failed($e->getMessage());
        }
    }

    /**
     * @param callable(): VirtualCardResult $call
     *
     * @throws UnsupportedByGateway
     */
    private function boundCard(callable $call): VirtualCardResult
    {
        try {
            return $call();
        } catch (UnsupportedByGateway $e) {
            throw $e;
        } catch (Throwable $e) {
            return VirtualCardResult::failed($e->getMessage());
        }
    }
}
