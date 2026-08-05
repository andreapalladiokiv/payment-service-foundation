<?php

declare(strict_types=1);

namespace Techork\PaymentService\Revolut;

use Omnipay\Common\Message\AbstractResponse;
use Override;
use Techork\PaymentService\Gateway\Contract\VirtualCardResponseInterface;
use Techork\PaymentService\Gateway\Contract\VirtualCardResult;

/**
 * Wraps the `DELETE /api/1.0/cards/{cardId}` (204 No Content) outcome.
 * Surfaces a {@see VirtualCardResult} carrying the terminated card guid.
 */
final class TerminateCardResponse extends AbstractResponse implements VirtualCardResponseInterface
{
    #[Override]
    public function isSuccessful(): bool
    {
        return ($this->data['terminated'] ?? false) === true;
    }

    #[Override]
    public function getTransactionReference(): ?string
    {
        return $this->data['cardGuid'] ?? null;
    }

    #[Override]
    public function getMessage(): ?string
    {
        return $this->data['error'] ?? null;
    }

    #[Override]
    public function toVirtualCardResult(): VirtualCardResult
    {
        if (! $this->isSuccessful()) {
            return VirtualCardResult::failed($this->getMessage() ?? 'Revolut card termination failed.');
        }

        return VirtualCardResult::succeeded(
            cardGuid: (string) ($this->data['cardGuid'] ?? ''),
            status: 'terminated',
        );
    }
}
