<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use GuzzleHttp\Exception\GuzzleException;
use Override;
use RuntimeException;
use Techork\PaymentService\Common\Contract\PaymentInstrumentVisitor;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\AttachedPaymentMethod;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\ConnexPay\Concern\BuildsConnexPayPayload;
use Techork\PaymentService\ConnexPay\Concern\FormatsThreeDS;
use Techork\PaymentService\ConnexPay\Concern\MapsConnexPayOutcome;
use Techork\PaymentService\Gateway\Command\VaultCommand;
use Techork\PaymentService\Gateway\Contract\RegistrationResult;
use Techork\PaymentService\Gateway\Exception\UnsupportedInstrument;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;

/**
 * Tokenizes a payment instrument through ConnexPay's Verify endpoint — `POST /api/v1/verify`,
 * a $0 verification whose answer carries the card GUID that becomes the token's reference.
 *
 * Only a raw card can be tokenized: the other three instruments are already something the
 * provider or we are holding, so there is nothing here to hand over.
 *
 * @implements PaymentInstrumentVisitor<array>
 */
final class CreateCard implements PaymentInstrumentVisitor
{
    use BuildsConnexPayPayload;
    use FormatsThreeDS;
    use MapsConnexPayOutcome;

    private const string VERIFY_PATH = '/api/v1/verify';

    /**
     * @param  ?ThreeDSResult  $threeDS  The attestation to forward, separate from the command
     *   because {@see VaultCommand} has no field for one — see {@see visitCreditCard()}. The
     *   gateway therefore passes none; the parameter exists so that the moment the command grows
     *   the field, forwarding it is one argument rather than a rewrite.
     */
    public function __construct(
        private readonly ConnexPaySettings $settings,
        private readonly VaultCommand $command,
        private readonly GatewayInfrastructure $infrastructure,
        private readonly ConnexPayHttpClientInterface $client,
        private readonly ?ThreeDSResult $threeDS = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->command->instrument->accept($this);
    }

    public function tokenize(): RegistrationResult
    {
        try {
            $response = $this->client->post(self::VERIFY_PATH, $this->payload());
        } catch (GuzzleException $e) {
            return RegistrationResult::failed($e->getMessage());
        }

        // Verify answers with the card nested; the guid it names is the token, and the customer
        // ConnexPay created alongside it is the one a later payment binds to. Nothing else in the
        // body is a registration signal — including the AVS / CVV letters, which a tokenization
        // is not asked to report and {@see CreatePaymentMethod} is.
        return $this->registration([
            'wasProcessed' => ($response['wasProcessed'] ?? false) === true,
            'guid' => $response['card']['guid'] ?? null,
            'customerGuid' => $response['card']['customer']['guid'] ?? null,
            'status' => $response['status'] ?? null,
            'processorResponseMessage' => $response['processorResponseMessage'] ?? null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function visitCreditCard(CreditCard $card): array
    {
        $decrypter = $this->infrastructure->decrypter;

        $data = [
            'DeviceGuid' => $this->settings->deviceGuid,
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

        $customer = $this->command->customer;
        if ($customer !== null) {
            $data['Card']['Customer'] = $this->formatCustomer($customer);
        }

        // Tokenization also goes through /verify, so an authentication result
        // must travel with it rather than being discarded.
        $threeDS = $this->formatThreeDS($this->threeDS);
        if ($threeDS !== null) {
            $data['Card']['ThreeDS'] = $threeDS;
        }

        return $data;
    }

    #[Override]
    public function visitCash(Cash $cash): never
    {
        throw new RuntimeException('ConnexPay does not support cash tokenization.');
    }

    #[Override]
    public function visitToken(Token $token): never
    {
        throw new RuntimeException('Token does not support tokenization.');
    }

    #[Override]
    public function visitPaymentMethod(PaymentMethod $paymentMethod): never
    {
        throw new RuntimeException('PaymentMethod does not support tokenization.');
    }

    /**
     * An attached one is refused for the same reason as a bare one: this operation is what
     * PRODUCES a stored instrument, so being handed one is a caller's mistake either way, and
     * having a customer attached does not make a stored card re-storable.
     */
    #[Override]
    public function visitAttachedPaymentMethod(AttachedPaymentMethod $attached): never
    {
        throw new RuntimeException('PaymentMethod does not support tokenization.');
    }

    #[Override]
    public function visitHostedPayment(HostedPayment $hosted): never
    {
        throw UnsupportedInstrument::forGateway('connexpay', 'createCard', $hosted);
    }
}
