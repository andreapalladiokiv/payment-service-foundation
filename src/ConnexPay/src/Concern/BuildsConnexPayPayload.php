<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay\Concern;

use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Transliterator;

/**
 * The field conventions every ConnexPay body shares.
 *
 * It used to be a set of parameter accessors over Omnipay's bag — `getDeviceGuid()`,
 * `getClientUniqueId()`, `setMoney()` — which is why the same trait could be used by a request
 * that had a money and one that never did. What is left is only the shaping: the two identifiers,
 * the two address blocks and the expiry format. Everything a value used to be READ from is now a
 * constructor argument of the operation, so the trait takes what it needs as parameters instead
 * of reaching for it.
 */
trait BuildsConnexPayPayload
{
    /**
     * The caller's `clientUniqueId` IS the business order number for
     * ConnexPay — it lands on merchant-facing reports and is the only
     * Search/Sales filter that can later locate the transaction (guid
     * filters are silently ignored by that endpoint). Forward it on every
     * endpoint that accepts `OrderNumber`; omit the key when absent.
     *
     * The bridge ports suffix the aggregate id with ":capture" / ":cancel"
     * for gateways with idempotency-key semantics (see
     * the gateway roles). ConnexPay has no such concept — repeating
     * the same OrderNumber across the auth, capture and void of one intent
     * is correct and keeps Search/Sales lookups working — so the synthetic
     * suffix is stripped rather than leaked into merchant-facing reports.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function withOrderNumber(array $data, ?string $clientUniqueId): array
    {
        if ($clientUniqueId !== null && $clientUniqueId !== '') {
            $data['OrderNumber'] = preg_replace('/:(?:capture|cancel)$/', '', $clientUniqueId);
        }

        return $data;
    }

    /**
     * ConnexPay's duplicate detection, which `OrderNumber` never was.
     *
     * The docs make the split plain: `OrderNumber` is "commonly used for reporting on and
     * reconciling your PayIns and PayOuts", searchable in Bridge and carried into the
     * Chargeback Management System; `SequenceNumber` is the one to "provide a unique
     * SequenceNumber for each new request", where a repeat within thirty minutes "will be
     * considered a duplicate request". Sandbox agrees: two auth-onlys with one OrderNumber,
     * one amount and no SequenceNumber come back as two different guids — two holds on one
     * cardholder's card.
     *
     * So this keeps the `:capture` / `:cancel` suffix that {@see withOrderNumber} strips.
     * The two fields want opposite things from it: the order number ties one payment's
     * operations together for reporting, and the sequence number tells them apart so a
     * capture is not taken for the authorization it settles.
     *
     * Punctuation goes. The field is documented as 100 alpha-numeric characters and, unlike
     * `OrderNumber`, names no permitted specials — so a UUID's hyphens and the suffix's
     * colon are dropped rather than gambled on.
     *
     * What this does NOT buy is replay safety. The window is thirty minutes; a job retried
     * later authorizes again. That has to be stopped before the call ever leaves — see
     * {@see \Techork\PaymentService\Laravel\Port\CreateAdapter}.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function withSequenceNumber(array $data, ?string $clientUniqueId): array
    {
        if ($clientUniqueId === null || $clientUniqueId === '') {
            return $data;
        }

        $sequence = substr((string) preg_replace('/[^A-Za-z0-9]/', '', $clientUniqueId), 0, 100);

        if ($sequence !== '') {
            $data['SequenceNumber'] = $sequence;
        }

        return $data;
    }

    /**
     * Both of ConnexPay's identifiers, which every documented endpoint takes together and
     * which mean different things: one names the payment, the other names this request.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function withIdentifiers(array $data, ?string $clientUniqueId): array
    {
        return $this->withSequenceNumber($this->withOrderNumber($data, $clientUniqueId), $clientUniqueId);
    }

    protected function formatExpirationDate(string $month, string $year): string
    {
        return substr($year, -2).str_pad($month, 2, '0', STR_PAD_LEFT);
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatRiskData(BillingAddress $address): array
    {
        return [
            'Name' => $address->firstName.' '.$address->lastName,
            'BillingPhoneNumber' => $address->phone ? (string) $address->phone : null,
            'BillingState' => $address->state ? (string) $address->state : null,
            'BillingCountryCode' => (string) $address->country,
            'Email' => $address->email ? (string) $address->email : null,
            'BillingAddress1' => $address->line,
            'BillingAddress2' => $address->lineExtra,
            'BillingPostalCode' => $address->postalCode,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatCustomer(BillingAddress $address): array
    {
        return [
            'FirstName' => $address->firstName,
            'LastName' => $address->lastName,
            'Phone' => $address->phone ? (string) $address->phone : null,
            'City' => self::transliterate($address->city),
            'State' => $address->state ? (string) $address->state : null,
            'Country' => (string) $address->country,
            'Email' => $address->email ? (string) $address->email : null,
            'Address1' => $address->line,
            'Address2' => $address->lineExtra,
            'Zip' => $address->postalCode,
        ];
    }

    /**
     * ConnexPay rejects non-ASCII input on Customer fields ("München",
     * "Kraków" fail validation), so fold accents down to their ASCII
     * equivalents. Prefers ext-intl (locale-independent), falls back to
     * iconv, and to the original value when neither can transliterate.
     */
    protected static function transliterate(string $value): string
    {
        if (class_exists(Transliterator::class)) {
            $result = Transliterator::create('Any-Latin; Latin-ASCII')?->transliterate($value);

            if (is_string($result)) {
                return $result;
            }
        }

        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT', $value);

        return $ascii === false ? $value : $ascii;
    }
}
