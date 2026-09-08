<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Command;

use Money\Money;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Return money that was taken.
 *
 * One command for both halves of a refund — back to the original card, and onto another one when
 * that card cannot accept it. They are different provider primitives (ConnexPay's Return with a
 * retry card, Nuvei's payout) but the same decision with the same fields, and which one runs is
 * the caller's sequencing rather than a different request to describe.
 */
final readonly class RefundCommand
{
    /**
     * @param  ?PaymentInstrument  $retryInstrument  Where to send the money when the original card
     *   refuses it. Read only by {@see \Techork\PaymentService\Gateway\Role\RefundsPayments::retryRefund()};
     *   its presence is also what tells a caller there is a second attempt worth making.
     */
    public function __construct(
        public GatewayId $gatewayId,
        public string $transactionReference,
        public Money $amount,
        public ?string $clientUniqueId = null,
        public ?PaymentInstrument $retryInstrument = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'gatewayId' => $this->gatewayId->toString(),
            'transactionReference' => $this->transactionReference,
            'amount' => $this->amount,
            'clientUniqueId' => $this->clientUniqueId,
            'retryInstrument' => $this->retryInstrument?->toPayload(),
        ];
    }
}
