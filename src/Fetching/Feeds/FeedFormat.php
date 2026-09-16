<?php

declare(strict_types=1);

namespace AiBlog\Fetching\Feeds;

/**
 * The feed formats {@see FeedParser} reads.
 */
enum FeedFormat: string
{
    case Rss = 'rss';
    case Rdf = 'rdf';
    case Atom = 'atom';
    case Json = 'json';
}
