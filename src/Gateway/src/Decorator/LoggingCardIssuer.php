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
use Techork\PaymentService\Gateway\ValueObject\SaleFunded;

/**
 * {@see LoggingGateway} for the issuing stack, and it writes its context the same way: here, once
 * per operation, from the command and the result this decorator already holds.
 *
 * Two fields of {@see IssueCardCommand} are deliberately not logged, and neither is marked
 * `#[Pii]` — the command carries no erasure obligation, which is what that attribute is for. So
 * the rule is applied to them by what they are: `firstName` and `lastName` are the cardholder's
 * name embossed on the card, the same fact {@see \Techork\PaymentService\Common\ValueObject\
 * CustomerIdentity} marks as personal data, and a name is not needed to correlate an issuance.
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
        $this->request('issueVirtualCard', [
            'gatewayId' => $command->gatewayId->toString(),
            ...$this->funding($command),
            'amountLimit' => $command->amountLimit,
            'spendCategory' => $command->spendCategory->value,
            'limitWindow' => $command->limitWindow?->value,
            'cardBrand' => $command->cardBrand?->value,
            'clientUniqueId' => $command->clientUniqueId,
        ]);

        $result = $this->inner->issueVirtualCard($command);
        $this->response('issueVirtualCard', $command->clientUniqueId, self::virtualCard($result));

        return $result;
    }

    #[Override]
    public function updateVirtualCard(UpdateCardCommand $command): VirtualCardResult
    {
        $this->request('updateVirtualCard', [
            'gatewayId' => $command->gatewayId->toString(),
            'cardGuid' => $command->cardGuid,
            'amountLimit' => $command->amountLimit,
            'spendCategory' => $command->spendCategory->value,
            'limitWindow' => $command->limitWindow?->value,
        ]);

        $result = $this->inner->updateVirtualCard($command);
        $this->response('updateVirtualCard', null, self::virtualCard($result));

        return $result;
    }

    #[Override]
    public function terminateVirtualCard(TerminateCardCommand $command): GatewayResult
    {
        $this->request('terminateVirtualCard', [
            'gatewayId' => $command->gatewayId->toString(),
            'cardGuid' => $command->cardGuid,
        ]);

        $result = $this->inner->terminateVirtualCard($command);
        $this->response('terminateVirtualCard', null, [
            'success' => $result->success,
            'reference' => $result->reference,
            'message' => $result->message,
        ]);

        return $result;
    }

    /**
     * Which funding model the card was asked for, and the sale it draws on when there is one.
     *
     * The negated `instanceof` against the one case that carries data is the shape
     * {@see \Techork\PaymentService\ConnexPay\ConnexPayGateway::issueVirtualCard()} uses to choose
     * between its two endpoints, and it is the shape here for the same reason: the pair is closed
     * and only one of the two has anything to say.
     *
     * A balance-funded card emits no `transactionReference` key at all rather than a null one — it
     * has no reference for a reader to find empty. The sale case always emits `fundingHint`, whose
     * value is the hint's *class* and never its insides: whatever a gateway put in there belongs to
     * that gateway, and a log line every gateway writes is the wrong place to spell it out.
     *
     * @return array<string, mixed>
     */
    private function funding(IssueCardCommand $command): array
    {
        $funding = $command->funding;

        if (! $funding instanceof SaleFunded) {
            return ['fundingModel' => $funding->model()->value];
        }

        return [
            'fundingModel' => $funding->model()->value,
            'transactionReference' => $funding->transactionReference,
            'fundingHint' => $funding->hint === null ? null : $funding->hint::class,
        ];
    }

    /**
     * Everything about an issued card except its secrets.
     *
     * `cardNumber` and `cvv` are deliberately absent: the log line the router wrote used to carry
     * both, and a sanitiser downstream was the only thing between a PAN and the log file. Not
     * emitting them is the guarantee; scrubbing them afterwards is a hope.
     *
     * @return array<string, mixed>
     */
    private static function virtualCard(VirtualCardResult $result): array
    {
        return [
            'success' => $result->success,
            'cardGuid' => $result->cardGuid,
            'expirationDate' => $result->expirationDate,
            'status' => $result->status,
            'message' => $result->message,
        ];
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
