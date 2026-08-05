<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use GuzzleHttp\Exception\GuzzleException;
use Money\Money;
use Omnipay\Common\Message\AbstractRequest;
use Override;
use RuntimeException;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\Contract\PaymentInstrumentVisitor;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Gateway\Concern\InstrumentParameters;
use Techork\PaymentService\ConnexPay\Concern\ConnexPayRequestParameters;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;

/**
 * Retries a previously declined Return against an alternative card.
 *
 * ConnexPay's Returns endpoint accepts a `ReturnRetryCard` payload that
 * redirects the refund onto a different card. The native semantic is
 * specifically retry-after-decline (the original Return must have been
 * declined within the 30-day window); the gateway will reject the request
 * if no prior declined Return exists for the SaleGuid.
 *
 * Expects: money, transactionReference (SaleGuid), instrument (the
 * alternative card / token / stored payment method).
 *
 * @implements PaymentInstrumentVisitor<array>
 */
final class ReturnRetryRequest extends AbstractRequest implements PaymentInstrumentVisitor
{
    use ConnexPayRequestParameters;
    use InstrumentParameters;

    #[Override]
    public function getData(): array
    {
        $this->validate('money', 'transactionReference', 'instrument');

        /** @var Money $money */
        $money = $this->getParameter('money');

        /** @var PaymentInstrument $instrument */
        $instrument = $this->getParameter('instrument');

        return $this->withOrderNumber([
            'DeviceGuid' => $this->getDeviceGuid(),
            'SaleGuid' => $this->getParameter('transactionReference'),
            'Amount' => (float) $this->formatMoney($money),
            'ReturnRetryCard' => $instrument->accept($this),
        ]);
    }

    #[Override]
    public function visitCreditCard(CreditCard $card): array
    {
        $decrypter = $this->getDecrypter();

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

    #[Override]
    public function visitToken(Token $token): array
    {
        /** @var GatewayCredential $gateway */
        $gateway = $this->getParameter('gateway');

        $reference = $this->getReferenceResolver()->find($gateway->getId(), $token)
            ?? throw new RuntimeException("No ConnexPay reference found for token {$token->id->toString()}.");

        return ['Guid' => $reference];
    }

    #[Override]
    public function visitPaymentMethod(PaymentMethod $paymentMethod): array
    {
        /** @var GatewayCredential $gateway */
        $gateway = $this->getParameter('gateway');

        $reference = $this->getReferenceResolver()->find($gateway->getId(), $paymentMethod)
            ?? throw new RuntimeException("No ConnexPay reference found for payment method {$paymentMethod->id->toString()}.");

        return ['Guid' => $reference];
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

    #[Override]
    public function sendData($data): RefundResponse
    {
        try {
            $response = $this->getConnexPayClient()->post('/api/v1/returns', $data);

            return new RefundResponse($this, $response);
        } catch (GuzzleException $e) {
            return new RefundResponse($this, [
                'wasProcessed' => false,
                'guid' => null,
                'processorResponseMessage' => $e->getMessage(),
            ]);
        }
    }
}
