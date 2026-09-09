<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject\CreditCard;

use Override;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\Contract\PaymentInstrumentVisitor;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\Token;

/**
 * Extracts the PCI-safe {@see CardSummary} from any card-bearing payment
 * instrument, unwrapping Token / PaymentMethod. Returns null for instruments
 * that carry no card (cash, hosted payment) — there is nothing to screen.
 *
 * @implements PaymentInstrumentVisitor<CardSummary|null>
 */
final class CardSummaryExtractor implements PaymentInstrumentVisitor
{
    public static function from(PaymentInstrument $instrument): ?CardSummary
    {
        return $instrument->accept(new self);
    }

    #[Override]
    public function visitCreditCard(CreditCard $card): ?CardSummary
    {
        return new CardSummary(
            $card->number->first6,
            $card->number->last4,
            $card->number->brand,
            $card->expiration,
            $card->holder,
        );
    }

    #[Override]
    public function visitToken(Token $token): ?CardSummary
    {
        return $token->instrument->accept($this);
    }

    #[Override]
    public function visitPaymentMethod(PaymentMethod $paymentMethod): ?CardSummary
    {
        return $paymentMethod->instrument->accept($this);
    }

    #[Override]
    public function visitCash(Cash $cash): ?CardSummary
    {
        return null;
    }

    #[Override]
    public function visitHostedPayment(HostedPayment $hosted): ?CardSummary
    {
        return null;
    }
}
