<?php

declare(strict_types=1);

namespace ByteMinus\AiBlog\Fetching\Feeds;

/**
 * A feed after parsing: which format it was, its title and home page when it
 * gave them, and at most the parser's item limit of entries in feed order.
 */
final readonly class ParsedFeed
{
    /**
     * @param  list<FeedItem>  $items
     */
    public function __construct(
        public FeedFormat $format,
        public array $items,
        public ?string $title = null,
        public ?string $homePageUrl = null,
        public bool $truncated = false,
    ) {}
}
