<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent;

use Techork\PaymentService\Common\Contract\ChallengeResult;
use Techork\PaymentService\Common\Contract\ChallengeResultVisitor;
use Techork\PaymentService\Common\ValueObject\Challenge\RedirectResult;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;

/**
 * Persistence-layer serializer for {@see ChallengeResult}. Discriminator
 * `type` lets the factory rebuild the concrete implementation on replay.
 *
 * @implements ChallengeResultVisitor<array<string, mixed>>
 */
final class ChallengeResultArraySerializer implements ChallengeResultVisitor
{
    public static function toArray(ChallengeResult $result): array
    {
        return $result->accept(new self);
    }

    public static function fromArray(array $payload): ChallengeResult
    {
        return match ($payload['type']) {
            'three_ds' => ThreeDSResult::fromPayload($payload['three_ds']),
            'redirect' => new RedirectResult(transactionId: $payload['redirect']['transaction_id']),
        };
    }

    public function visitThreeDS(ThreeDSResult $result): array
    {
        return [
            'type' => 'three_ds',
            'three_ds' => $result->toPayload(),
        ];
    }

    public function visitRedirect(RedirectResult $result): array
    {
        return [
            'type' => 'redirect',
            'redirect' => ['transaction_id' => $result->transactionId],
        ];
    }
}
