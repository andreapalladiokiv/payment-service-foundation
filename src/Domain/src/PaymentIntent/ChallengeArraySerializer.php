<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent;

use Override;
use Techork\PaymentService\Common\Contract\Challenge;
use Techork\PaymentService\Common\Contract\ChallengeVisitor;
use Techork\PaymentService\Common\ValueObject\Challenge\RedirectChallenge;
use Techork\PaymentService\Common\ValueObject\Challenge\SdkChallenge;
use Techork\PaymentService\Common\ValueObject\Challenge\ThreeDSChallenge;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSVersion;

/**
 * Persistence-layer serializer for {@see Challenge}. Emits/consumes a `type`
 * discriminator so the factory can reconstruct the concrete implementation on
 * replay. Kept in the domain package because serialization is a domain-event
 * concern, not a common-VO one.
 *
 * The 3DS keys were renamed — `transaction_id`/`acs_url`/`creq` became
 * `authentication_id`/`url`/`payload` — when it turned out the old names were a vendor's rather
 * than the protocol's, and that `transaction_id` had been carrying three different things
 * depending on which adapter wrote it. Reading falls back to the old keys because rows written
 * under them exist; writing does not, so the ambiguity does not spread. `client_secret`,
 * `method_url` and `method_data` are gone entirely and are not read back: nothing carried them
 * to any consumer, and the first of the three was a credential sitting in an append-only log.
 *
 * @implements ChallengeVisitor<array<string, mixed>>
 */
final class ChallengeArraySerializer implements ChallengeVisitor
{
    public static function toArray(Challenge $challenge): array
    {
        return $challenge->accept(new self);
    }

    public static function fromArray(array $payload): Challenge
    {
        return match ($payload['type']) {
            'three_ds' => new ThreeDSChallenge(
                $payload['three_ds']['authentication_id'] ?? $payload['three_ds']['transaction_id'],
                $payload['three_ds']['url'] ?? $payload['three_ds']['acs_url'],
                $payload['three_ds']['payload'] ?? $payload['three_ds']['creq'] ?? null,
                // Falls back rather than failing: the enum names the one version this project
                // speaks, so an unknown string is a row from a wider world, not a broken row.
                ThreeDSVersion::tryFrom((string) ($payload['three_ds']['protocol_version'] ?? ''))
                    ?? ThreeDSVersion::V220,
            ),
            'sdk' => new SdkChallenge(
                $payload['sdk']['authentication_id'],
                $payload['sdk']['payment_reference'],
            ),
            'redirect' => new RedirectChallenge(
                $payload['redirect']['transaction_id'],
                $payload['redirect']['url'],
                $payload['redirect']['form_fields'],
            ),
        };
    }

    #[Override]
    public function visitThreeDS(ThreeDSChallenge $challenge): array
    {
        return [
            'type' => 'three_ds',
            'three_ds' => [
                'authentication_id' => $challenge->authenticationId,
                'url' => $challenge->url,
                'payload' => $challenge->payload,
                'protocol_version' => $challenge->protocolVersion->value,
            ],
        ];
    }

    #[Override]
    public function visitSdk(SdkChallenge $challenge): array
    {
        return [
            'type' => 'sdk',
            'sdk' => [
                'authentication_id' => $challenge->authenticationId,
                'payment_reference' => $challenge->paymentReference,
            ],
        ];
    }

    #[Override]
    public function visitRedirect(RedirectChallenge $challenge): array
    {
        return [
            'type' => 'redirect',
            'redirect' => [
                'transaction_id' => $challenge->transactionId,
                'url' => $challenge->url,
                'form_fields' => $challenge->formFields,
            ],
        ];
    }
}
