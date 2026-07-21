<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\Contract;

use Techork\PaymentService\Common\ValueObject\Risk\FraudScreeningRequest;
use Techork\PaymentService\Common\ValueObject\Risk\FraudVerdict;

/**
 * A fraud-screening backend (implemented by the Forter sub-package). Given a
 * card transaction it returns the provider's recommendation.
 *
 * Implementations MUST NOT throw for a business outcome — a decline is a
 * {@see FraudVerdict}, not an exception. On provider unavailability (timeout,
 * transport error, malformed response) implementations SHOULD return a
 * {@see \Techork\PaymentService\Common\ValueObject\Risk\FraudDecision::Errored}
 * verdict rather than throwing, so the decision layer can apply a uniform
 * fail-open / fail-closed policy. Exceptions are reserved for programming
 * errors (invalid configuration, un-mappable input).
 */
interface FraudScreeningProvider
{
    public function screen(FraudScreeningRequest $request): FraudVerdict;
}
