<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject\Challenge;

use Techork\PaymentService\Common\Contract\Challenge;
use Techork\PaymentService\Common\Contract\ChallengeVisitor;

/**
 * Hosted-page redirect challenge. The cardholder's browser must POST
 * `formFields` to `url` (typically as a hidden-input HTML form) to reach the
 * gateway's hosted payment page.
 *
 * @phpstan-type FormFields array<string, string>
 */
final readonly class RedirectChallenge implements Challenge
{
    /**
     * @param  FormFields  $formFields
     */
    public function __construct(
        public string $transactionId,
        public string $url,
        public array $formFields,
    ) {}

    public function transactionId(): string
    {
        return $this->transactionId;
    }

    public function accept(ChallengeVisitor $visitor): mixed
    {
        return $visitor->visitRedirect($this);
    }
}
