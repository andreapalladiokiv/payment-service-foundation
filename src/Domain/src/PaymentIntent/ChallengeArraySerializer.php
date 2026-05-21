<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent;

use Techork\PaymentService\Common\Contract\Challenge;
use Techork\PaymentService\Common\Contract\ChallengeVisitor;
use Techork\PaymentService\Common\ValueObject\Challenge\RedirectChallenge;
use Techork\PaymentService\Common\ValueObject\Challenge\ThreeDSChallenge;

/**
 * Persistence-layer serializer for {@see Challenge}. Emits/consumes a `type`
 * discriminator so the factory can reconstruct the concrete implementation on
 * replay. Kept in the domain package because serialization is a domain-event
 * concern, not a common-VO one.
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
                $payload['three_ds']['transaction_id'],
                $payload['three_ds']['acs_url'] ?? null,
                $payload['three_ds']['creq'] ?? null,
                $payload['three_ds']['client_secret'] ?? null,
            ),
            'redirect' => new RedirectChallenge(
                $payload['redirect']['transaction_id'],
                $payload['redirect']['url'],
                $payload['redirect']['form_fields'],
            ),
        };
    }

    public function visitThreeDS(ThreeDSChallenge $challenge): array
    {
        return [
            'type' => 'three_ds',
            'three_ds' => [
                'transaction_id' => $challenge->transactionId,
                'acs_url' => $challenge->acsUrl,
                'creq' => $challenge->creq,
                'client_secret' => $challenge->clientSecret,
            ],
        ];
    }

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
