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
 * Registers an instrument as a reusable ConnexPay payment method via the Verify endpoint
 * (`POST /api/v1/verify`, a $0 verification) — for tokens this re-verifies the stored card GUID
 * together with the cardholder's Customer data, which is what makes ConnexPay create / link the
 * customer on their side and return fresh AVS / CVV signals. The legacy integration did the same
 * on `addPaymentMethod`; a local pass-through (what this used to be) skips customer creation
 * entirely.
 *
 * Same endpoint as {@see CreateCard} and a different operation all the same: that one hands
 * ConnexPay a card it has never seen, this one hands back one it already holds so the customer
 * gets attached to it.
 *
 * **This is where a ConnexPay customer is born, and it is the only place.** ConnexPay publishes no
 * endpoint that creates one from an identity alone — every one of them takes a card — which is why
 * {@see \Techork\PaymentService\Gateway\Role\RegistersCustomers} is refused for this gateway
 * and this registration does that job instead. The guid comes back as
 * `RegistrationResult::$customerReference` and pairs with the customer's own id from the same
 * command, so the caller holds both halves and can record them in
 * `GatewayCustomerRepository`. Until it does, that map answers null for this customer, and null is
 * an ordinary answer here rather than a failure.
 *
 * **What that made of the billing address until now.** `Card.Customer` was built from the address
 * alone, so the customer ConnexPay created was whoever the card happened to be billed to, and the
 * name, email and phone a merchant actually recorded never reached it. That is the
 * address-derived provider customer this whole change exists to end — removed for Nuvei and Stripe
 * first, and surviving here longest because the write-up had declared there was no customer object
 * on this gateway to look at. The command carries a whole
 * {@see \Techork\PaymentService\Common\ValueObject\Customer} now, so the person and the address
 * arrive together; see {@see \Techork\PaymentService\ConnexPay\Concern\BuildsConnexPayPayload::formatCustomer()}
 * for why both are needed rather than one standing in for the other.
 *
 * @implements PaymentInstrumentVisitor<array>
 */
final class CreatePaymentMethod implements PaymentInstrumentVisitor
{
    use BuildsConnexPayPayload;
    use FormatsThreeDS;
    use MapsConnexPayOutcome;

    private const string VERIFY_PATH = '/api/v1/verify';

    /**
     * @param  ?ThreeDSResult  $threeDS  The attestation to forward, separate from the command
     *   because {@see VaultCommand} has no field for one — see {@see payload()}. The gateway
     *   therefore passes none; the parameter exists so that the moment the command grows the
     *   field, forwarding it is one argument rather than a rewrite.
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
        $card = $this->command->instrument->accept($this);

        // One check where there were two. The address and the identity were separate optional
        // arguments, so "either is enough to be worth sending" was a real branch; a
        // {@see \Techork\PaymentService\Common\ValueObject\Customer} has both or is absent.
        $customer = $this->command->customer;
        if ($customer !== null) {
            $card['Customer'] = $this->formatCustomer($customer);
        }

        // A registration that was authenticated must carry the result through,
        // or the 3DS step-up is performed and then discarded.
        $threeDS = $this->formatThreeDS($this->threeDS);
        if ($threeDS !== null) {
            $card['ThreeDS'] = $threeDS;
        }

        return [
            'DeviceGuid' => $this->settings->deviceGuid,
            'Card' => $card,
        ];
    }

    public function register(): RegistrationResult
    {
        try {
            $response = $this->client->post(self::VERIFY_PATH, $this->payload());
        } catch (GuzzleException $e) {
            return RegistrationResult::failed($e->getMessage());
        }

        // Unlike a tokenization, this one reports the verification letters: re-verifying a stored
        // card against the cardholder's address is the whole point, so the AVS / CVV answers are
        // the signal the caller asked for.
        return $this->registration([
            'wasProcessed' => ($response['wasProcessed'] ?? false) === true,
            'guid' => $response['card']['guid'] ?? null,
            'customerGuid' => $response['card']['customer']['guid'] ?? null,
            'status' => $response['status'] ?? null,
            'processorResponseMessage' => $response['processorResponseMessage'] ?? null,
            'addressVerificationCode' => $response['addressVerificationCode'] ?? null,
            'cvvVerificationCode' => $response['cvvVerificationCode'] ?? null,
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

    #[Override]
    public function visitCash(Cash $cash): never
    {
        throw new RuntimeException('Cash cannot be stored as a payment method.');
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function visitToken(Token $token): array
    {
        $reference = $this->infrastructure->instruments->find($this->infrastructure->credential->getId(), $token)
            ?? throw new RuntimeException("No ConnexPay reference found for token {$token->id->toString()}.");

        $data = ['Guid' => $reference];

        if ($token->instrument instanceof CreditCard) {
            $cvv = $token->instrument->cvc->getCvc($this->infrastructure->decrypter);
            if ($cvv !== null && $cvv !== '') {
                $data['Cvv2'] = $cvv;
            }
        }

        return $data;
    }

    #[Override]
    public function visitPaymentMethod(PaymentMethod $paymentMethod): never
    {
        throw new RuntimeException('PaymentMethod cannot be re-stored as a payment method.');
    }

    #[Override]
    public function visitHostedPayment(HostedPayment $hosted): never
    {
        throw UnsupportedInstrument::forGateway('connexpay', 'createPaymentMethod', $hosted);
    }
}
