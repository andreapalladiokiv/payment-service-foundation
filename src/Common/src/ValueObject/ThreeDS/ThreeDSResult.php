<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject\ThreeDS;

use Techork\PaymentService\Common\Contract\ChallengeResult;
use Techork\PaymentService\Common\Contract\ChallengeResultVisitor;

/**
 * Terminal artefact of a completed 3DS authentication. When present, it is
 * the evidence a merchant forwards to the acquiring gateway (external MPI
 * mode) to claim the liability shift. Interim challenge state is modelled
 * separately by {@see \Techork\PaymentService\Common\ValueObject\Challenge\ThreeDSChallenge}.
 */
final readonly class ThreeDSResult implements ChallengeResult
{
    public function __construct(
        public ThreeDSStatus $status,
        public ?string $authenticationValue,
        public ?ECICode $eci,
        public string $dsTransactionId,
        public string $acsTransactionId,
        public ?ThreeDSVersion $version = null,
    ) {}

    public function accept(ChallengeResultVisitor $visitor): mixed
    {
        return $visitor->visitThreeDS($this);
    }

    public function toPayload(): array
    {
        return [
            'status' => $this->status->value,
            'authentication_value' => $this->authenticationValue,
            'eci' => $this->eci?->value,
            'ds_transaction_id' => $this->dsTransactionId,
            'acs_transaction_id' => $this->acsTransactionId,
            'version' => $this->version?->value,
        ];
    }

    public static function fromPayload(array $payload): self
    {
        return new self(
            ThreeDSStatus::from($payload['status']),
            $payload['authentication_value'] ?? null,
            isset($payload['eci']) ? ECICode::from($payload['eci']) : null,
            $payload['ds_transaction_id'],
            $payload['acs_transaction_id'],
            isset($payload['version']) ? ThreeDSVersion::from($payload['version']) : null,
        );
    }
}
