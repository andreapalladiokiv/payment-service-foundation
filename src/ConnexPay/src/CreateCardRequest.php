<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use Techork\PaymentService\Gateway\Concern\InstrumentParameters;
use Techork\PaymentService\ConnexPay\Concern\ConnexPayRequestParameters;
use GuzzleHttp\Exception\GuzzleException;
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
 * Tokenizes a payment instrument via ConnexPay's Verify endpoint ($0 verification).
 * For credit cards: returns the card GUID as the transaction reference.
 */
final class CreateCardRequest extends AbstractRequest implements PaymentInstrumentVisitor
{
    use ConnexPayRequestParameters;
    use InstrumentParameters;

    public function getData(): array
    {
        /** @var PaymentInstrument $instrument */
        $instrument = $this->getParameter('instrument');

        return $instrument->accept($this);
    }

    public function visitCreditCard(CreditCard $card): array
    {
        $decrypter = $this->getDecrypter();

        $data = [
            'DeviceGuid' => $this->getDeviceGuid(),
            'Card' => [
                'CardHolderName' => (string) $card->holder,
                'CardNumber' => $card->number->getNumber($decrypter),
                'ExpirationDate' => $this->formatExpirationDate(
                    $card->expiration->format('m'),
                    $card->expiration->format('Y'),
                ),
            ],
        ];

        $cvv = $card->cvc->getCvc($decrypter);
        if ($cvv !== null && $cvv !== '') {
            $data['Card']['Cvv2'] = $cvv;
        }

        $billingAddress = $this->getParameter('billingAddress');
        if ($billingAddress !== null) {
            $data['Card']['Customer'] = $this->formatCustomer($billingAddress);
        }

        return $data;
    }

    public function visitCash(Cash $cash): mixed
    {
        throw new RuntimeException('ConnexPay does not support cash tokenization.');
    }

    public function visitToken(Token $token): never
    {
        throw new RuntimeException('Token does not support tokenization.');
    }

    public function visitPaymentMethod(PaymentMethod $paymentMethod): never
    {
        throw new RuntimeException('PaymentMethod does not support tokenization.');
    }

    public function sendData($data): CreateCardResponse
    {
        try {
            $response = $this->getConnexPayClient()->post('/api/v1/verify', $data);

            return new CreateCardResponse($this, [
                'wasProcessed' => ($response['wasProcessed'] ?? false) === true,
                'guid' => $response['card']['guid'] ?? null,
                'customerGuid' => $response['card']['customer']['guid'] ?? null,
                'status' => $response['status'] ?? null,
                'processorResponseMessage' => $response['processorResponseMessage'] ?? null,
            ]);
        } catch (GuzzleException $e) {
            return new CreateCardResponse($this, [
                'wasProcessed' => false,
                'guid' => null,
                'processorResponseMessage' => $e->getMessage(),
            ]);
        }
    }

    public function visitHostedPayment(HostedPayment $hosted): never
    {
        throw new \RuntimeException('Gateway does not support hosted-payment instruments.');
    }
}
