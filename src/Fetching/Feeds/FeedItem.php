<?php

declare(strict_types=1);

namespace ByteMinus\AiBlog\Fetching\Feeds;

use Carbon\CarbonImmutable;

/**
 * One entry of a feed, the same shape whatever the format. `url` is always an
 * absolute http or https URL; `guid` is the feed's own id for the entry, or
 * the URL when it gave none. Title and summary are plain text taken from the
 * feed and are untrusted.
 */
final readonly class FeedItem
{
    public function __construct(
        public string $url,
        public string $guid,
        public ?string $title = null,
        public ?CarbonImmutable $publishedAt = null,
        public ?string $summary = null,
    ) {}

    /**
     * @return array{url: string, guid: string, title: ?string, published_at: ?string, summary: ?string}
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'guid' => $this->guid,
            'title' => $this->title,
            'published_at' => $this->publishedAt?->toIso8601String(),
            'summary' => $this->summary,
        ];
    }
}
