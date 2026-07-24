<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CreditCard\CardSummary;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\Email;
use Techork\PaymentService\Common\ValueObject\Risk\ConnectionContext;
use Techork\PaymentService\Common\ValueObject\Risk\IpAddress;
use Techork\PaymentService\Common\ValueObject\Risk\FraudScreeningRequest;
use Techork\PaymentService\Forter\ForterHttpClientInterface;

/**
 * A FraudScreeningRequest with sensible defaults for Forter tests.
 */
function makeForterScreeningRequest(int $amountMinorUnits = 12345, string $currency = 'USD', bool $withOptionalBilling = true): FraudScreeningRequest
{
    return new FraudScreeningRequest(
        reference: 'fraud-ref-1',
        card: new CardSummary('411111', '1111', CardBrand::Visa, Expiration::fromMonthAndYear(6, 2030), new Holder('John Doe')),
        billing: new BillingAddress(
            firstName: 'John',
            lastName: 'Doe',
            line: '1 Main St',
            city: 'New York',
            country: new Country('US'),
            postalCode: '10001',
            email: $withOptionalBilling ? new Email('john@example.com') : null,
        ),
        amountMinorUnits: $amountMinorUnits,
        currencyCode: $currency,
        connection: new ConnectionContext(new IpAddress('203.0.113.7'), 'Mozilla/5.0'),
    );
}

/**
 * A fake Forter HTTP client returning a canned response (or throwing).
 *
 * @param  array<string, mixed>  $response
 */
function fakeForterClient(array $response = [], ?Throwable $throws = null): ForterHttpClientInterface
{
    return new class($response, $throws) implements ForterHttpClientInterface
    {
        /** @param array<string, mixed> $response */
        public function __construct(private array $response, private ?Throwable $throws) {}

        public function postOrder(string $orderId, array $body): array
        {
            if ($this->throws !== null) {
                throw $this->throws;
            }

            return $this->response;
        }
    };
}
