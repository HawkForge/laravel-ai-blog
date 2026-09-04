<?php

declare(strict_types=1);

namespace AiBlog\Fetching;

use Carbon\CarbonImmutable;

/**
 * What a research fetch brought back, exactly as the server sent it (after
 * any gzip or deflate was undone). The body is untrusted data: turn it into
 * prompt text through {@see TextExtractor} and {@see UntrustedText}, never by
 * pasting it into a prompt.
 *
 * `finalUrl` is the address after any redirects; `requestedUrl` is the one
 * asked for. `sha256` is of `body`, so two snapshots of the same page can be
 * compared without comparing bodies.
 */
final readonly class FetchedDocument
{
    /**
     * @param  list<string>  $redirects  Every URL that answered with a redirect, in order.
     */
    public function __construct(
        public string $requestedUrl,
        public string $finalUrl,
        public int $status,
        public string $contentType,
        public string $body,
        public string $sha256,
        public CarbonImmutable $fetchedAt,
        public array $redirects = [],
    ) {}

    /**
     * The media type without parameters, lower-cased: `text/html`.
     */
    public function mediaType(): string
    {
        return strtolower(trim(explode(';', $this->contentType, 2)[0]));
    }

    /**
     * The charset the server declared, when it declared one.
     */
    public function charset(): ?string
    {
        if (preg_match('/;\s*charset\s*=\s*"?([A-Za-z0-9._:-]+)"?/i', $this->contentType, $match) === 1) {
            return strtoupper($match[1]);
        }

        return null;
    }

    public function isHtml(): bool
    {
        return in_array($this->mediaType(), ['text/html', 'application/xhtml+xml'], true);
    }
}
