<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay\Concern;

use Techork\PaymentService\Common\ValueObject\Customer;
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
    protected function formatRiskData(Customer $customer): array
    {
        $identity = $customer->identity;
        $address = $customer->billingAddress;

        return [
            'Name' => $identity->firstName.' '.$identity->lastName,
            'BillingPhoneNumber' => $identity->phone ? (string) $identity->phone : null,
            'BillingState' => $address->state ? (string) $address->state : null,
            'BillingCountryCode' => (string) $address->country,
            'Email' => $identity->email ? (string) $identity->email : null,
            'BillingAddress1' => $address->line,
            'BillingAddress2' => $address->lineExtra,
            'BillingPostalCode' => $address->postalCode,
        ];
    }

    /**
     * Our own customer id, as ConnexPay's `CustomerID`.
     *
     * Its documented job is to be "a secondary identifier in conjunction with OrderNumber",
     * searchable in the portal: one names the payment, the other names who made it. Up to 100
     * characters, alphanumeric plus `[._/-]` — a UUID's hyphens are fine here, unlike in
     * `SequenceNumber`, which lists no permitted punctuation at all.
     *
     * **Applied by hand on the three endpoints that accept it** — Auth Only, Create Sale and
     * Capture — and deliberately NOT folded into {@see withIdentifiers()}, because Void and Return
     * do not list the field. Sending one anyway is the kind of guess that had this adapter reading
     * response fields ConnexPay never returns.
     *
     * Sandbox-verified 2026-08-20: Auth Only accepts it and echoes it back in the response.
     *
     * **Optional, and that is the whole answer to the one question left open here.** ConnexPay
     * documents that a Capture's `OrderNumber` overwrites the Auth's and says nothing about
     * `CustomerID`; whether a capture without one blanks what the auth recorded cannot be
     * established in sandbox, where auths stay at `Transaction - CreatedLocal` and the capture is
     * refused with 422 first. It does not need establishing. The field is omitted when no customer
     * was named and sent on all three accepting endpoints when one was, so a caller that names a
     * customer sends the same value at every step — an overwrite writes what was already there —
     * and a caller that names none never had the value to lose. Neither branch depends on the
     * unanswered question, which is what closes it rather than parks it.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function withCustomerId(array $data, ?Customer $customer): array
    {
        if ($customer !== null) {
            $data['CustomerID'] = substr((string) preg_replace('/[^A-Za-z0-9._\/-]/', '', $customer->id->toString()), 0, 100);
        }

        return $data;
    }

    /**
     * ConnexPay's `Customer` block, which is two things at once and had only one source.
     *
     * The four person fields — name, phone, email — say who the customer is, and ConnexPay creates
     * or links a customer object from them, returning its guid as `card.customer.guid`. The six
     * address fields are the AVS payload: they are what makes `addressVerificationCode` come back
     * at all. That split is why the identity does not REPLACE the address here: substituting one
     * for the other would register the right person and silently end address verification, since
     * a {@see \Techork\PaymentService\Common\ValueObject\CustomerIdentity} holds no address by
     * design.
     *
     * A {@see Customer} carries both halves, and the mapping is exact: its identity's four fields
     * are precisely the four this block has that an address should never have decided. It used to
     * take the two separately with the address answering for both when no identity was passed,
     * which was not a last resort but the normal case — every ConnexPay customer was created from
     * whoever the card happened to be billed to. One argument that has both makes that fallback
     * unnecessary rather than merely unlikely.
     *
     * Names are transliterated for the same reason the city always was: ConnexPay rejects
     * non-ASCII on this block, and a customer's own name is far likelier to carry an accent than
     * anything that survived being typed into an address form.
     *
     * @return array<string, mixed>
     */
    protected function formatCustomer(Customer $customer): array
    {
        $identity = $customer->identity;
        $address = $customer->billingAddress;
        $state = $address->state;

        return [
            'FirstName' => self::transliterate($identity->firstName),
            'LastName' => self::transliterate($identity->lastName),
            'Phone' => $identity->phone ? (string) $identity->phone : null,
            'City' => self::transliterate($address->city),
            'State' => $state === null ? null : (string) $state,
            'Country' => (string) $address->country,
            'Email' => $identity->email ? (string) $identity->email : null,
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
