<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use Override;
use RuntimeException;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\Contract\PaymentInstrumentVisitor;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\ConnexPay\Concern\BuildsConnexPayPayload;
use Techork\PaymentService\ConnexPay\Concern\MapsConnexPayOutcome;
use Techork\PaymentService\Gateway\Command\RefundCommand;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;
use Techork\PaymentService\Gateway\Exception\UnsupportedInstrument;

/**
 * Retries a previously declined Return against an alternative card.
 *
 * ConnexPay's Returns endpoint accepts a `ReturnRetryCard` payload that
 * redirects the refund onto a different card. The native semantic is
 * specifically retry-after-decline (the original Return must have been
 * declined within the 30-day window); the gateway will reject the request
 * if no prior declined Return exists for the SaleGuid.
 *
 * The alternative card is {@see RefundCommand::$retryInstrument}, which is nullable because a
 * plain refund has none — so its absence is refused here rather than reaching the visitor as a
 * null. It used to be `validate('instrument')` on a bag that could hold either.
 *
 * @implements PaymentInstrumentVisitor<array>
 */
final class ReturnRetry implements PaymentInstrumentVisitor
{
    use BuildsConnexPayPayload;
    use MapsConnexPayOutcome;

    private const string RETURNS_PATH = '/api/v1/returns';

    public function __construct(
        private readonly ConnexPaySettings $settings,
        private readonly RefundCommand $command,
        private readonly GatewayInfrastructure $infrastructure,
        private readonly ConnexPayHttpClientInterface $client,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->withIdentifiers([
            'DeviceGuid' => $this->settings->deviceGuid,
            'SaleGuid' => $this->command->transactionReference,
            'Amount' => (float) $this->settings->formatAmount($this->command->amount),
            'ReturnRetryCard' => $this->retryInstrument()->accept($this),
        ], $this->command->clientUniqueId);
    }

    public function retry(): GatewayResult
    {
        try {
            return $this->outcome($this->client->post(self::RETURNS_PATH, $this->payload()));
        } catch (GuzzleException $e) {
            return GatewayResult::failed($e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function visitCreditCard(CreditCard $card): array
    {
        $decrypter = $this->infrastructure->decrypter;

        $data = [
            'CardNumber' => $card->number->getNumber($decrypter),
            'ExpirationDate' => $this->formatExpirationDate(
                $card->expiration->format('m'),
                $card->expiration->format('Y'),
            ),
        ];

        $cvv = $card->cvc->getCvc($decrypter);
        if ($cvv !== null && $cvv !== '') {
            $data['Cvv2'] = $cvv;
        }

        $name = (string) $card->holder;
        if ($name !== '') {
            $data['CardHolderName'] = $name;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function visitToken(Token $token): array
    {
        return ['Guid' => $this->storedReference($token, "token {$token->id->toString()}")];
    }

    /**
     * Refused when nobody has claimed the card, and that is the whole reason this is one
     * method rather than two.
     *
     * There was a `visitAttachedPaymentMethod()` beside a `visitPaymentMethod()` that only
     * threw, which made "payable" something a signature carried. Attached is a state of a
     * payment method — see {@see PaymentMethod::isAttached()} — so the branch became a guard.
     * What it costs is that the guarantee is now checked rather than typed; what it buys is
     * one credential with one identity everywhere downstream of here.
     */
    #[Override]
    public function visitPaymentMethod(PaymentMethod $paymentMethod): array
    {
        // A stored card is charged to somebody, and an unattached one names nobody. The
        // payer is not derivable from the card: what used to answer was the address the
        // payment method carried, so a card was charged to whoever it was billed to.
        $paymentMethod->isAttached() || throw UnsupportedInstrument::needsAttachedCustomer('connexpay', 'retryRefund', $paymentMethod);

        return ['Guid' => $this->storedReference($paymentMethod, "payment method {$paymentMethod->id->toString()}")];
    }

    #[Override]
    public function visitCash(Cash $cash): never
    {
        throw new RuntimeException('Cash is not a valid retry refund instrument.');
    }

    #[Override]
    public function visitHostedPayment(HostedPayment $hosted): never
    {
        throw new RuntimeException('HostedPayment is not a valid retry refund instrument.');
    }

    /**
     * Unreachable from production: {@see \Techork\PaymentService\Laravel\Port\RefundAdapter}
     * only reaches `retryRefund()` when `$request->retryInstrument !== null`, and that is the
     * value the command's field is built from. It replaces `validate('instrument')` on a bag that
     * could hold either kind of refund, and says the thing that bag could not: which instrument
     * is missing and what it was for.
     */
    private function retryInstrument(): PaymentInstrument
    {
        return $this->command->retryInstrument ?? throw new InvalidArgumentException(
            'ConnexPay cannot retry a Return without the alternative card to send the money to.',
        );
    }

    private function storedReference(Token|PaymentMethod $instrument, string $describedAs): string
    {
        return $this->infrastructure->instruments->find($this->infrastructure->credential->getId(), $instrument)
            ?? throw new RuntimeException("No ConnexPay reference found for {$describedAs}.");
    }
}
