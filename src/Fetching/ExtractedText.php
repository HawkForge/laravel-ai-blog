<?php

declare(strict_types=1);

namespace ByteMinus\AiBlog\Fetching;

use Carbon\CarbonImmutable;

/**
 * The readable text of a fetched document, with what could be found about
 * it. Every field came from the page and is untrusted: put it in a prompt
 * only through {@see self::untrusted()}.
 */
final readonly class ExtractedText
{
    public function __construct(
        public string $sourceUrl,
        public string $text,
        public ?string $title = null,
        public ?string $author = null,
        public ?CarbonImmutable $publishedAt = null,
        public bool $truncated = false,
    ) {}

    /**
     * The whole extract, metadata included, fenced as data.
     */
    public function untrusted(): UntrustedText
    {
        $lines = [];

        if ($this->title !== null) {
            $lines[] = 'Title: '.$this->title;
        }

        if ($this->author !== null) {
            $lines[] = 'Author: '.$this->author;
        }

        if ($this->publishedAt !== null) {
            $lines[] = 'Published: '.$this->publishedAt->toDateString();
        }

        $header = $lines === [] ? '' : implode("\n", $lines)."\n\n";

        return new UntrustedText($header.$this->text.($this->truncated ? "\n[text cut at the length limit]" : ''), $this->sourceUrl);
    }
}
