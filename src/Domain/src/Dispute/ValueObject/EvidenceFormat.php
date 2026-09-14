<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\ValueObject;

/**
 * How one piece of evidence travels to whichever side ends up receiving it.
 *
 * Text, because much of the evidence is prose — a correspondence thread, a policy, an
 * explanation of what happened — and the submission APIs that take it count characters
 * rather than bytes.
 *
 * Pdf and Jpeg, because the two file-based flows in this domain accept exactly those and
 * nothing else: Nuvei takes one base64 PDF per request, and the ConnexPay portal takes
 * PDF or JPEG. A closed enum rather than an open media-type string, so that offering a
 * PNG is a decision someone has to make rather than a value someone can pass.
 */
enum EvidenceFormat: string
{
    /** Plain text; `content` is the text itself. */
    case Text = 'text';

    /** `content` is a base64-encoded PDF. */
    case Pdf = 'pdf';

    /** `content` is a base64-encoded JPEG. */
    case Jpeg = 'jpeg';

    /** Whether `content` is encoded bytes rather than text. */
    public function isFile(): bool
    {
        return $this !== self::Text;
    }
}
