<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject\Risk;

/**
 * The client-side signals a fraud check needs about the connection the
 * cardholder is using: their IP address and browser user-agent. Captured at
 * payment-method registration and reused at authorization.
 *
 * `deviceToken` is the optional device/behavioral fingerprint token from a
 * front-end fraud SDK (Forter's `forterTokenCookie`). We do not embed such an
 * SDK ourselves, but a caller that already has one (e.g. supplied by the
 * client) may pass it through to sharpen the screening.
 */
final readonly class ConnectionContext
{
    public function __construct(
        public string $ipAddress,
        public string $userAgent,
        public ?string $deviceToken = null,
    ) {}
}
