<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\ValueObject;

use InvalidArgumentException;

/**
 * One piece of evidence as it crosses into a provider: the fact it answers, the content, and
 * whether the content is bytes.
 *
 * ## The fact is a string here, and that is the layer boundary rather than a loose type
 *
 * The vocabulary of facts — proof of delivery, the AVS result, the accepted terms — is the
 * domain's ({@see \Techork\PaymentService\Domain\Dispute\ValueObject\EvidenceType}) and this
 * package may not name it: a provider package implements a `Gateway` role and never a domain port
 * (§0.4 of the dispute plan), and `Stripe` is allowed `Common` and `Gateway` and nothing else. So
 * the item carries the fact's own name — the value of that enum, `proof_of_delivery_or_service` —
 * and it is the adapter in `Laravel/Port` that reads it off the domain's object on the way out.
 * Nothing maps back in: a provider field is mapped outward from this name and never parsed for it.
 *
 * ## Why the transport is a media type rather than "text or pdf or jpeg"
 *
 * The domain's own closed set ({@see \Techork\PaymentService\Domain\Dispute\ValueObject\EvidenceFormat})
 * exists so that offering a PNG is a decision somebody has to make rather than a value somebody can
 * pass, and that decision is made on the domain's side of the boundary. What a provider needs is
 * the MIME type for the part it uploads, so the media type is what travels — `null` for text, the
 * type of the bytes otherwise — and no enum is restated here that would have to be kept in step
 * with the domain's.
 *
 * ## Nothing here validates the content beyond its presence
 *
 * Not the base64, not a size, not a character count. Those limits belong to the side that imposes
 * them — one provider caps an upload at 2 MB, another counts 150,000 characters of text across a
 * whole submission — and a check here would be the wrong one for whichever provider read it. The
 * domain's item already refused a file that is not base64 before this object existed; a provider
 * that finds it undecodable anyway refuses it with its own typed exception.
 */
final readonly class DisputeEvidenceItem
{
    /**
     * @param  string  $type  the fact this answers, in the domain's own vocabulary, as its enum
     *   value — see the class docblock for why it is a string at this layer
     * @param  string  $content  the text itself, or base64 bytes when `$mediaType` says it is a file
     * @param  ?string  $mediaType  the MIME type of `$content` once decoded, or null when the
     *   content is text. Null is not "unknown": a document of unknown type is not something a
     *   provider can be handed, and guessing `application/octet-stream` there would send a part
     *   the network's portal cannot open.
     */
    public function __construct(
        public string $type,
        public string $content,
        public ?string $mediaType = null,
    ) {
        trim($type) !== ''
            || throw new InvalidArgumentException('An evidence item must name the fact it answers');

        $content !== ''
            || throw new InvalidArgumentException("Evidence for \"{$type}\" is empty");

        $mediaType === null || trim($mediaType) !== ''
            || throw new InvalidArgumentException(
                "Evidence for \"{$type}\" declares a file with no media type. A provider uploads "
                . 'bytes under a content type, and an empty one reaches the network as a part '
                . 'nobody can open.',
            );
    }

    /** Whether the content is bytes to upload rather than text to put in a field. */
    public function isFile(): bool
    {
        return $this->mediaType !== null;
    }

    /**
     * How many characters a provider that counts text would count.
     *
     * Zero for a file, and that is the point of the method: a submission's text limit is over the
     * text fields, while a file's bytes do not travel as text at all — they are uploaded and the
     * field carries the id. Counting base64 as text would refuse a PDF far below any limit; a
     * character count here is a count of the text a network reads.
     */
    public function textCharacters(): int
    {
        return $this->isFile() ? 0 : mb_strlen($this->content);
    }

    /**
     * What a log line may say about this item.
     *
     * The content is deliberately absent and never logged: it is a customer's correspondence, a
     * signed delivery note, or a page of base64, and a log is not where any of those belongs.
     *
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'type' => $this->type,
            'mediaType' => $this->mediaType,
            'characters' => $this->textCharacters(),
        ];
    }
}
