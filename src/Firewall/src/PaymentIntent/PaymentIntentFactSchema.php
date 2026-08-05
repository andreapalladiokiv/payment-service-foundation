<?php

declare(strict_types=1);

namespace Techork\PaymentService\Firewall\PaymentIntent;

use Override;
use Techork\PaymentService\Firewall\Dsl\FactSchema;
use Techork\PaymentService\Firewall\Dsl\FieldType;

/**
 * The fact vocabulary a payment-intent rule may talk about.
 *
 * Three roots, matching where each fact comes from:
 *  - `payment_method` — the instrument and its context. What the request carried
 *    (`source.bin`, `billing_address.*`, `connection.ip`) plus whatever
 *    enrichment suppliers add (`source.issuer_country`,
 *    `connection.is_proxy`, …).
 *  - `payment_intent` — the transaction: amount, currency, gateway.
 *  - `screening` — a fraud provider's verdict, exposed as facts so rules can
 *    weigh it instead of it being decided ahead of them.
 *
 * {@see paths()} declares types so authored literals are coerced correctly — a
 * boolean fact compared against the string "false" would otherwise invert — and
 * so an admin UI can offer the right widget per fact. The list is advisory, not
 * a boundary: the sandbox is {@see roots()}, and an unlisted sub-path is allowed
 * and simply untyped.
 *
 * Facts an enrichment supplier could not obtain are ABSENT rather than false or
 * zero, so a rule referencing them does not match. Declaring a path here does
 * not promise it will be present.
 */
final readonly class PaymentIntentFactSchema implements FactSchema
{
    #[Override]
    public function roots(): array
    {
        return ['payment_method', 'payment_intent', 'screening'];
    }

    #[Override]
    public function typeOf(string $path): FieldType
    {
        return self::paths()[$path] ?? FieldType::Mixed;
    }

    /**
     * Declared fact paths and their types.
     *
     * @return array<string, FieldType>
     */
    public static function paths(): array
    {
        return [
            // payment_method.source — the card itself, then BIN enrichment
            'payment_method.source.bin' => FieldType::Text,
            'payment_method.source.last4' => FieldType::Text,
            'payment_method.source.brand' => FieldType::Text,
            'payment_method.source.expiry_month' => FieldType::Number,
            'payment_method.source.expiry_year' => FieldType::Number,
            'payment_method.source.is_expired' => FieldType::Boolean,
            'payment_method.source.issuer_country' => FieldType::Text,
            'payment_method.source.funding' => FieldType::Text,
            'payment_method.source.is_prepaid' => FieldType::Boolean,
            'payment_method.source.is_commercial' => FieldType::Boolean,
            // contributed by the application from its own trust statistics
            'payment_method.source.bin_success_rate' => FieldType::Number,
            'payment_method.source.bin_challenge_rate' => FieldType::Number,

            // payment_method.billing_address
            'payment_method.billing_address.first_name' => FieldType::Text,
            'payment_method.billing_address.last_name' => FieldType::Text,
            'payment_method.billing_address.country' => FieldType::Text,
            'payment_method.billing_address.city' => FieldType::Text,
            'payment_method.billing_address.postal_code' => FieldType::Text,
            'payment_method.billing_address.state' => FieldType::Text,
            'payment_method.billing_address.email' => FieldType::Text,
            'payment_method.billing_address.phone' => FieldType::Text,

            // payment_method.connection — request origin, then IP enrichment
            'payment_method.connection.ip' => FieldType::Text,
            'payment_method.connection.user_agent' => FieldType::Text,
            'payment_method.connection.has_device_token' => FieldType::Boolean,
            'payment_method.connection.country' => FieldType::Text,
            'payment_method.connection.is_proxy' => FieldType::Boolean,
            'payment_method.connection.is_vpn' => FieldType::Boolean,
            'payment_method.connection.host_domain' => FieldType::Text,

            // payment_intent — the transaction under inspection
            'payment_intent.id' => FieldType::Text,
            'payment_intent.amount' => FieldType::Number,
            'payment_intent.currency' => FieldType::Text,
            'payment_intent.gateway_id' => FieldType::Text,

            // screening — a fraud provider's verdict, now matchable
            'screening.decision' => FieldType::Text,
            'screening.reason_code' => FieldType::Text,
            'screening.reference' => FieldType::Text,
            'screening.is_approved' => FieldType::Boolean,
            'screening.is_declined' => FieldType::Boolean,
            'screening.is_inconclusive' => FieldType::Boolean,
        ];
    }
}
