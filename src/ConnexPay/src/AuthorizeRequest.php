<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use Techork\PaymentService\Gateway\Concern\InstrumentParameters;
use Techork\PaymentService\ConnexPay\Concern\ConnexPayRequestParameters;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Exception\UnsupportedInstrument;
use GuzzleHttp\Exception\GuzzleException;
use Money\Money;
use Omnipay\Common\Message\AbstractRequest;
use RuntimeException;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\Contract\PaymentInstrumentVisitor;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;
use Techork\PaymentService\Common\ValueObject\Token;

/**
 * Authorizes (holds) funds via ConnexPay without capturing.
 * Expects: money (Money), instrument (PaymentInstrument), gateway (Gateway).
 */
final class AuthorizeRequest extends AbstractRequest implements PaymentInstrumentVisitor
{
    use ConnexPayRequestParameters;
    use InstrumentParameters;

    public function getData(): array
    {
        $this->validate('money', 'instrument', 'gateway');

        /** @var Money $money */
        $money = $this->getParameter('money');

        /** @var PaymentInstrument $instrument */
        $instrument = $this->getParameter('instrument');

        $data = [
            'DeviceGuid' => $this->getDeviceGuid(),
            'Amount' => (float) $this->formatMoney($money),
        ];

        $statementDescription = $this->getStatementDescription();
        if ($statementDescription !== null && $statementDescription !== '') {
            $data['StatementDescription'] = $statementDescription;
        }

        $data['TenderType'] = 'Credit';
        $data['Card'] = $instrument->accept($this);

        $threeDS = $this->formatThreeDS();
        if ($threeDS !== null) {
            $data['Card']['ThreeDS'] = $threeDS;
        }

        $billingAddress = $this->getParameter('billingAddress');
        if ($billingAddress !== null) {
            $data['RiskData'] = $this->formatRiskData($billingAddress);
        }

        return $this->withOrderNumber($data);
    }

    public function visitCreditCard(CreditCard $card): array
    {
        $decrypter = $this->getDecrypter();

        $data = [
            'CardHolderName' => (string) $card->holder,
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

        return $data;
    }

    public function visitCash(Cash $cash): never
    {
        throw new RuntimeException('ConnexPay /authonlys does not support cash; route cash payments through purchase() instead.');
    }

    public function visitToken(Token $token): array
    {
        /** @var GatewayCredential $gateway */
        $gateway = $this->getParameter('gateway');

        $reference = $this->getReferenceResolver()->find($gateway->getId(), $token)
            ?? throw new RuntimeException("No ConnexPay reference found for token {$token->id->toString()}.");

        return ['Guid' => $reference];
    }

    public function visitPaymentMethod(PaymentMethod $paymentMethod): array
    {
        /** @var GatewayCredential $gateway */
        $gateway = $this->getParameter('gateway');

        $reference = $this->getReferenceResolver()->find($gateway->getId(), $paymentMethod)
            ?? throw new RuntimeException("No ConnexPay reference found for payment method {$paymentMethod->id->toString()}.");

        return ['Guid' => $reference];
    }

    public function sendData($data): AuthorizeResponse
    {
        try {
            $response = $this->getConnexPayClient()->post('/api/v1/authonlys', $data);

            return new AuthorizeResponse($this, $response);
        } catch (GuzzleException $e) {
            return new AuthorizeResponse($this, [
                'wasProcessed' => false,
                'guid' => null,
                'processorResponseMessage' => $e->getMessage(),
            ]);
        }
    }

    public function visitHostedPayment(HostedPayment $hosted): never
    {
        throw UnsupportedInstrument::forGateway('connexpay', 'authorize', $hosted);
    }
}
