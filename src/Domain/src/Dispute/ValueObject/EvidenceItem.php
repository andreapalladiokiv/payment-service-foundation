<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\ValueObject;

use InvalidArgumentException;

/**
 * One piece of evidence, already collected.
 *
 * A requirement states which types a reason code asks for ({@see EvidenceRequirements});
 * an item is the thing itself — the delivery note, the support thread, the descriptor —
 * carried towards whichever side receives it ({@see EvidencePackage}).
 *
 * **Files are carried as base64**, and the constructor checks that they decode. Both
 * file-based flows want base64 (Nuvei's upload is explicitly base64, and PDF and JPEG are
 * what the portal takes) and the standing failure is handing them raw bytes — which the
 * provider answers with a decode error that reads like a provider fault rather than like
 * ours. The check is here for the same reason {@see \Techork\PaymentService\Common\ValueObject\IpAddress}
 * validates an address: an item that could never be accepted should not be constructible.
 *
 * Nothing here counts characters or enforces a size. Those limits belong to the side that
 * imposes them — one network counts text, another caps an upload — and a shared number
 * here would be the wrong one for whichever adapter read it second.
 */
final readonly class EvidenceItem
{
    public function __construct(
        public EvidenceType $type,
        public string $content,
        public EvidenceFormat $format = EvidenceFormat::Text,
    ) {
        $content !== '' || throw new InvalidArgumentException("Evidence for $type->value is empty");

        // Strict decoding, which rejects anything outside the base64 alphabet — a PDF
        // handed over as raw bytes is the mistake this catches, and it is the one that
        // actually happens. It cannot tell a base64 file from base64-shaped text, and does
        // not pretend to; whitespace inside is tolerated, which is what line-wrapped
        // encoders emit.
        if ($format->isFile() && base64_decode($content, true) === false) {
            throw new InvalidArgumentException("Evidence for $type->value is a $format->value and must be base64-encoded");
        }
    }
}
