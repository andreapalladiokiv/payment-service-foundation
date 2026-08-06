<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Webhook\Contract;

use Psr\Http\Message\ServerRequestInterface;
use Techork\PaymentService\Gateway\Webhook\WebhookRouter;

/**
 * One inbound webhook, read exactly once, offered to as many verifiers as it takes to find the
 * tenant it belongs to.
 *
 * This type exists to make a bug unrepresentable rather than to carry anything new. Verifiers used
 * to take the PSR-7 request, and a webhook is offered to every candidate credential in turn — so
 * the first verifier that read the raw body left the stream at EOF and every candidate after it
 * saw an empty string. Only the first credential of a kind could ever authenticate, and the
 * failure looked exactly like a wrong secret.
 *
 * The repair was a `rewind()` between candidates, which is where this type comes from. Three
 * things were wrong with it:
 *
 *  - it is guarded by `isSeekable()`, so on a non-seekable body — `php://input` under some SAPIs,
 *    which is the shape this most likely arrives in — it does nothing at all and the bug survives
 *    in exactly the deployment hardest to reproduce;
 *  - it puts the obligation on the caller for a state change the callee makes, so a verifier
 *    added later cannot be written wrongly, only used wrongly, and correctness sits somewhere the
 *    author of the fault never looks;
 *  - `(string) $stream` already rewinds seekable streams per PSR-7, so the line reads as
 *    redundant to anyone who knows that, and as load-bearing to anyone who does not.
 *
 * Reading once, before the loop, removes the shared position rather than managing it. A verifier
 * is handed values, has no stream to consume, and cannot affect what the next one sees.
 *
 * Everything a verifier had is still here — method, uri, every header, the body, the decoded
 * fields — because some providers sign over the path and some read a second header, and narrowing
 * this to "body plus one header" would trade one silent failure for another. The only thing taken
 * away is the ability to consume something.
 *
 * {@see WebhookRouter::identifyGateway()} is the only thing that builds one.
 */
final readonly class InboundWebhook
{
    /**
     * @param  array<string, string>  $headers  lower-cased name => value, joined as PSR-7 joins them
     * @param  array<string, mixed>|null  $parsed  whatever the PSR-7 layer decoded, if it decoded anything
     */
    private function __construct(
        public string $body,
        public string $method,
        public string $uri,
        private array $headers,
        private ?array $parsed,
    ) {}

    public static function from(ServerRequestInterface $request): self
    {
        $headers = [];

        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = implode(', ', $values);
        }

        $parsed = $request->getParsedBody();

        return new self(
            body: (string) $request->getBody(),
            method: $request->getMethod(),
            uri: (string) $request->getUri(),
            headers: $headers,
            parsed: is_array($parsed) ? $parsed : null,
        );
    }

    /**
     * Case-insensitive, and empty for a header that is not there — `getHeaderLine()`'s contract,
     * because every verifier was written against it.
     */
    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    /**
     * The body as fields, however it was encoded.
     *
     * Three sources in order of authority. What the PSR-7 layer decoded comes first, since a host
     * framework may know things about the request that the raw bytes do not say. JSON is next, and
     * it is next rather than absent because PSR-7 only promises to populate the parsed body for
     * form posts: a JSON webhook commonly arrives with nothing parsed, and the previous code went
     * straight from there to `parse_str`, which turns a JSON document into one nonsense key and
     * fails the signature check as if the secret were wrong.
     *
     * Form encoding is the fallback, which is what Nuvei's DMN posts actually are.
     *
     * @return array<string, mixed>
     */
    public function fields(): array
    {
        if ($this->parsed !== null && $this->parsed !== []) {
            return $this->parsed;
        }

        if ($this->body === '') {
            return [];
        }

        $json = json_decode($this->body, true);

        if (is_array($json)) {
            return $json;
        }

        $fields = [];
        parse_str($this->body, $fields);

        return $fields;
    }
}
