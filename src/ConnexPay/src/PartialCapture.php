<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\ConnexPay\Concern\MapsConnexPayOutcome;
use Techork\PaymentService\Gateway\Command\CaptureCommand;
use Techork\PaymentService\Gateway\Command\PlacementCommand;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Gateway\Exception\UnsupportedInstrument;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;

/**
 * Captures less than the authorized amount — ConnexPay has no native
 * primitive for that ("You can only capture the original amount that was
 * authorized", https://docs.connexpay.com/docs/auth-and-capture), so this
 * operation voids the AuthOnly and runs a fresh sale for the smaller amount
 * with the original instrument, exactly like the legacy integration.
 *
 * The sale half is {@see Purchase}, composed rather than inherited. The old request extended
 * `PurchaseRequest` and then had to override the hosted branch to keep a partial capture from
 * turning into a fresh hosted page — a redirect asking the buyer to pay again instead of settling
 * the existing auth. Composition removes the door rather than closing it: the only instrument
 * that reaches {@see Purchase} from here is one this class has already accepted. The refusal
 * below is kept anyway, and unreachable today for the same reason it was then (a hosted intent is
 * `Immediate` by invariant, so it is charged rather than authorized and never reaches capture) —
 * which is precisely why it is worth stating: if that invariant ever changes, a partial capture
 * must fail loudly rather than charge the buyer twice.
 *
 * Failure semantics match the legacy two-step: a failed void leaves the
 * auth intact and reports a failed capture; a failed sale after a
 * successful void also reports a failed capture, with the hold already
 * released (the retry repeats the whole two-step — because the stored
 * reference is only replaced on success, it voids the already voided
 * AuthOnly again and only then runs the new sale; the void is not
 * skipped).
 */
final class PartialCapture
{
    use MapsConnexPayOutcome;

    private const string VOID_PATH = '/api/v1/void';

    public function __construct(
        private readonly ConnexPaySettings $settings,
        private readonly CaptureCommand $command,
        private readonly GatewayInfrastructure $infrastructure,
        private readonly ConnexPayHttpClientInterface $client,
    ) {}

    /**
     * The sale that replaces the voided authorization — the same body {@see Purchase} builds for
     * an ordinary charge, because that is exactly what the second half of this is.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->sale()->payload();
    }

    public function capture(): GatewayResult
    {
        // Built before the void, so an instrument this operation refuses is refused while the
        // authorization is still intact.
        $sale = $this->payload();

        try {
            $this->client->post(self::VOID_PATH, [
                'DeviceGuid' => $this->settings->deviceGuid,
                'AuthOnlyGuid' => $this->command->transactionReference,
            ]);
        } catch (GuzzleException $e) {
            return GatewayResult::failed("Void before partial capture failed: {$e->getMessage()}");
        }

        try {
            $response = $this->client->post(Purchase::SALES_PATH, $sale);
        } catch (GuzzleException $e) {
            return GatewayResult::failed($e->getMessage());
        }

        // A capture's outcome, not a placement's: the sale replaces an authorization that already
        // opened the intent, so it reports the reference and the incoming transaction code and
        // does NOT claim to be the opening transaction.
        return $this->outcome($response);
    }

    private function sale(): Purchase
    {
        $instrument = $this->instrument();

        return new Purchase(
            $this->settings,
            new PlacementCommand(
                gatewayId: $this->command->gatewayId,
                instrument: $instrument,
                amount: $this->command->amount,
                clientUniqueId: $this->command->clientUniqueId,
                // Forwarded from the capture rather than dug out of the instrument. It used to
                // read the address off a stored `PaymentMethod`, which was the only instrument
                // carrying one — so a raw card reached the replacement sale with no RiskData, and
                // the payer on it was whoever the card was billed to rather than whoever the
                // capture named. ConnexPay's RiskData is optional on a sale, so null still works.
                customer: $this->command->customer,
            ),
            $this->infrastructure,
            $this->client,
        );
    }

    private function instrument(): PaymentInstrument
    {
        $instrument = $this->command->instrument ?? throw new InvalidArgumentException(
            'ConnexPay cannot capture a partial amount without the original instrument '
            .'(full-auth void + fresh sale is required).',
        );

        if ($instrument instanceof HostedPayment) {
            throw UnsupportedInstrument::forGateway('connexpay', 'partialCapture', $instrument);
        }

        return $instrument;
    }
}
