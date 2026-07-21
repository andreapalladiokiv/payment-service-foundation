<?php

declare(strict_types=1);

namespace Techork\PaymentService\Forter;

use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Formatter\DecimalMoneyFormatter;
use Money\Money;
use Money\MoneyFormatter;
use Techork\PaymentService\Common\ValueObject\Risk\FraudScreeningRequest;

/**
 * Maps a {@see FraudScreeningRequest} onto the JSON body Forter's `/orders`
 * endpoint expects (mirrors the legacy backoffice mapper). Only the PCI-safe
 * card summary (BIN + last four) and billing / connection data cross the wire
 * — never the PAN or CVV.
 *
 * The amount is emitted under `amountUSD` as a decimal string; the caller is
 * responsible for passing an amount in the currency Forter is configured for
 * (this mapper does no FX conversion). Timestamps are left to Forter to stamp
 * server-side so the mapper stays deterministic.
 */
final class ForterRequestMapper
{
    private const string DEFAULT_GENDER = 'CHOSE_NOT_TO_SPECIFY';

    private MoneyFormatter $moneyFormatter;

    public function __construct(?MoneyFormatter $moneyFormatter = null)
    {
        $this->moneyFormatter = $moneyFormatter ?? new DecimalMoneyFormatter(new ISOCurrencies);
    }

    /**
     * @return array<string, mixed>
     */
    public function toOrderPayload(FraudScreeningRequest $request): array
    {
        $amount = ['amountUSD' => $this->formatAmount($request->amountMinorUnits, $request->currencyCode)];
        $billing = $request->billing;
        $card = $request->card;

        $payment = [
            'billingDetails' => [
                'personalDetails' => [
                    'firstName' => $billing->firstName,
                    'lastName' => $billing->lastName,
                    'gender' => self::DEFAULT_GENDER,
                ],
                'address' => array_filter([
                    'address1' => $billing->line,
                    'address2' => $billing->lineExtra !== '' ? $billing->lineExtra : null,
                    'city' => $billing->city,
                    'country' => (string) $billing->country,
                    'zip' => $billing->postalCode,
                    'region' => $billing->state !== null ? (string) $billing->state : null,
                ], static fn ($value): bool => $value !== null),
            ],
            'amount' => $amount,
            'defaultPaymentMethod' => true,
            'creditCard' => [
                'nameOnCard' => (string) $card->holder,
                'bin' => $card->bin,
                'lastFourDigits' => $card->last4,
                'expirationMonth' => $card->expiration->format('m'),
                'expirationYear' => $card->expiration->format('Y'),
            ],
        ];

        if ($billing->phone !== null) {
            $payment['billingDetails']['phone'] = [['phone' => (string) $billing->phone]];
        }

        return [
            'orderId' => $request->reference,
            'orderType' => 'WEB',
            'authorizationStep' => 'PRE_AUTHORIZATION',
            'connectionInformation' => array_filter([
                'customerIP' => $request->connection->ipAddress,
                'userAgent' => $request->connection->userAgent,
                'forterTokenCookie' => $request->connection->deviceToken,
            ], static fn ($value): bool => $value !== null),
            'totalAmount' => $amount,
            'cartItems' => [[
                'basicItemData' => [
                    'name' => 'Payment',
                    'quantity' => 1,
                    'type' => 'NON_TANGIBLE',
                    'price' => $amount,
                    'productId' => $request->reference,
                    'category' => 'General',
                ],
                'deliveryDetails' => ['deliveryType' => 'DIGITAL', 'deliveryMethod' => 'Online'],
            ]],
            'payment' => [$payment],
            'accountOwner' => array_filter([
                'firstName' => $billing->firstName,
                'lastName' => $billing->lastName,
                'email' => $billing->email !== null ? (string) $billing->email : null,
            ], static fn ($value): bool => $value !== null),
        ];
    }

    private function formatAmount(int $minorUnits, string $currencyCode): string
    {
        return $this->moneyFormatter->format(new Money($minorUnits, new Currency($currencyCode)));
    }
}
