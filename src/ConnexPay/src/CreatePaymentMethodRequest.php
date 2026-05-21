<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use Techork\PaymentService\Gateway\Concern\InstrumentParameters;
use Techork\PaymentService\ConnexPay\Concern\ConnexPayRequestParameters;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Omnipay\Common\Message\AbstractRequest;
use RuntimeException;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\Contract\PaymentInstrumentVisitor;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\Token;

/**
 * In ConnexPay, the card GUID from tokenization is already reusable.
 * This request is a pass-through that resolves the token's gateway reference
 * and returns it as the payment method reference.
 * Expects: instrument (PaymentInstrument), gateway (Gateway).
 */
final class CreatePaymentMethodRequest extends AbstractRequest implements PaymentInstrumentVisitor
{
    use ConnexPayRequestParameters;
    use InstrumentParameters;

    public function getData(): array
    {
        $this->validate('instrument', 'gateway');

        /** @var PaymentInstrument $instrument */
        $instrument = $this->getParameter('instrument');

        return [
            'tokenReference' => $instrument->accept($this),
        ];
    }

    public function visitCreditCard(CreditCard $card): never
    {
        throw new RuntimeException('Credit card must be tokenized before creating a payment method with ConnexPay.');
    }

    public function visitCash(Cash $cash): never
    {
        throw new RuntimeException('Cash cannot be stored as a payment method.');
    }

    public function visitToken(Token $token): string
    {
        /** @var GatewayCredential $gateway */
        $gateway = $this->getParameter('gateway');

        return $this->getReferenceResolver()->find($gateway->getId(), $token)
            ?? throw new RuntimeException("No ConnexPay reference found for token {$token->id->toString()}.");
    }

    public function visitPaymentMethod(PaymentMethod $paymentMethod): never
    {
        throw new RuntimeException('PaymentMethod cannot be re-stored as a payment method.');
    }

    public function sendData($data): CreatePaymentMethodResponse
    {
        return new CreatePaymentMethodResponse($this, [
            'wasProcessed' => true,
            'guid' => $data['tokenReference'],
        ]);
    }

    public function visitHostedPayment(HostedPayment $hosted): never
    {
        throw new \RuntimeException('Gateway does not support hosted-payment instruments.');
    }
}
