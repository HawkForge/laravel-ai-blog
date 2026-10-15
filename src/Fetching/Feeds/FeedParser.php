<?php

declare(strict_types=1);

namespace ByteMinus\AiBlog\Fetching\Feeds;

use ByteMinus\AiBlog\Fetching\Exceptions\FeedUnreadable;
use ByteMinus\AiBlog\Fetching\FetchedDocument;
use ByteMinus\AiBlog\Fetching\UntrustedText;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Throwable;

/**
 * Reads RSS 2.0, RSS 1.0 (RDF), Atom and JSON Feed into one list of
 * {@see FeedItem}s.
 *
 * XML is parsed without the network, without loading or validating against
 * a DTD and without substituting entities; on top of that any document that
 * declares a DOCTYPE or an ENTITY is refused before it reaches the parser,
 * since a feed never needs one and that is where XXE and entity-expansion
 * attacks live. Sizes are bounded: the document, the number of items, and
 * every text field. Links are resolved against the feed's URL and kept only
 * when they are absolute http or https; an entry without one is skipped.
 *
 * Every field is untrusted text from the feed.
 */
final class FeedParser
{
    public const DEFAULT_MAX_ITEMS = 100;

    private const MAX_BYTES = 5 * 1024 * 1024;

    private const MAX_TITLE_CHARACTERS = 300;

    private const MAX_SUMMARY_CHARACTERS = 1000;

    private const MAX_GUID_CHARACTERS = 500;

    private const ATOM = 'http://www.w3.org/2005/Atom';

    private const RSS1 = 'http://purl.org/rss/1.0/';

    private const RDF = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';

    private const DUBLIN_CORE = 'http://purl.org/dc/elements/1.1/';

    private const CONTENT = 'http://purl.org/rss/1.0/modules/content/';

    public function parseDocument(FetchedDocument $document, int $maxItems = self::DEFAULT_MAX_ITEMS): ParsedFeed
    {
        return $this->parse($document->body, $document->finalUrl, $maxItems);
    }

    /**
     * @throws FeedUnreadable
     */
    public function parse(string $body, string $feedUrl, int $maxItems = self::DEFAULT_MAX_ITEMS): ParsedFeed
    {
        if (strlen($body) > self::MAX_BYTES) {
            throw FeedUnreadable::because('the document is too large');
        }

        $body = ltrim((string) preg_replace('/^\xEF\xBB\xBF/', '', $body));

        if ($body === '') {
            throw FeedUnreadable::because('the document is empty');
        }

        if ($body[0] === '{') {
            return $this->parseJson($body, $feedUrl, max(0, $maxItems));
        }

        return $this->parseXml($body, $feedUrl, max(0, $maxItems));
    }

    private function parseXml(string $body, string $feedUrl, int $maxItems): ParsedFeed
    {
        if (preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $body) === 1) {
            throw FeedUnreadable::because('XML feeds declaring a DOCTYPE or entities are refused');
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $dom = new DOMDocument;
            $dom->resolveExternals = false;
            $dom->substituteEntities = false;
            $dom->validateOnParse = false;

            $loaded = $dom->loadXML($body, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($loaded === false || $dom->documentElement === null) {
            throw FeedUnreadable::because('the XML is malformed');
        }

        if ($dom->doctype !== null) {
            throw FeedUnreadable::because('XML feeds declaring a DOCTYPE or entities are refused');
        }

        $root = $dom->documentElement;
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('atom', self::ATOM);
        $xpath->registerNamespace('rss1', self::RSS1);
        $xpath->registerNamespace('rdf', self::RDF);
        $xpath->registerNamespace('dc', self::DUBLIN_CORE);
        $xpath->registerNamespace('content', self::CONTENT);

        return match (true) {
            $root->localName === 'rss' => $this->parseRss($xpath, $feedUrl, $maxItems),
            $root->localName === 'feed' && $root->namespaceURI === self::ATOM => $this->parseAtom($xpath, $feedUrl, $maxItems),
            $root->localName === 'RDF' && $root->namespaceURI === self::RDF => $this->parseRdf($xpath, $feedUrl, $maxItems),
            default => throw FeedUnreadable::because('the document is not RSS, Atom or JSON Feed'),
        };
    }

    private function parseRss(DOMXPath $xpath, string $feedUrl, int $maxItems): ParsedFeed
    {
        $channel = ($xpath->query('/rss/channel') ?: null)?->item(0);

        if (! $channel instanceof DOMElement) {
            throw FeedUnreadable::because('the RSS document has no channel');
        }

        $homePage = $this->absoluteUrl($this->childText($xpath, $channel, 'link'), $feedUrl);
        $base = $homePage ?? $feedUrl;
        $items = [];
        $truncated = false;

        foreach ($xpath->query('item', $channel) ?: [] as $item) {
            if (count($items) >= $maxItems) {
                $truncated = true;

                break;
            }

            $guid = $this->childText($xpath, $item, 'guid');
            $guidIsLink = $guid !== null
                && strtolower((string) $xpath->evaluate('string(guid/@isPermaLink)', $item)) !== 'false';

            $url = $this->absoluteUrl($this->childText($xpath, $item, 'link'), $base)
                ?? ($guidIsLink ? $this->absoluteUrl($guid, $base) : null);

            $candidate = $this->item(
                url: $url,
                guid: $guid,
                title: $this->childText($xpath, $item, 'title'),
                published: $this->childText($xpath, $item, 'pubDate') ?? $this->childText($xpath, $item, 'dc:date'),
                summary: $this->childText($xpath, $item, 'description') ?? $this->childText($xpath, $item, 'content:encoded'),
            );

            if ($candidate !== null) {
                $items[] = $candidate;
            }
        }

        return new ParsedFeed(
            FeedFormat::Rss,
            $items,
            $this->title($this->childText($xpath, $channel, 'title')),
            $homePage,
            $truncated,
        );
    }

    private function parseAtom(DOMXPath $xpath, string $feedUrl, int $maxItems): ParsedFeed
    {
        $feed = ($xpath->query('/atom:feed') ?: null)?->item(0);

        if (! $feed instanceof DOMElement) {
            throw FeedUnreadable::because('the Atom document has no feed element');
        }

        $homePage = $this->absoluteUrl($this->atomLink($xpath, $feed), $feedUrl);
        $items = [];
        $truncated = false;

        foreach ($xpath->query('atom:entry', $feed) ?: [] as $entry) {
            if (count($items) >= $maxItems) {
                $truncated = true;

                break;
            }

            $candidate = $this->item(
                url: $this->absoluteUrl($this->atomLink($xpath, $entry), $feedUrl),
                guid: $this->childText($xpath, $entry, 'atom:id'),
                title: $this->childText($xpath, $entry, 'atom:title'),
                published: $this->childText($xpath, $entry, 'atom:published') ?? $this->childText($xpath, $entry, 'atom:updated'),
                summary: $this->childText($xpath, $entry, 'atom:summary') ?? $this->childText($xpath, $entry, 'atom:content'),
            );

            if ($candidate !== null) {
                $items[] = $candidate;
            }
        }

        return new ParsedFeed(
            FeedFormat::Atom,
            $items,
            $this->title($this->childText($xpath, $feed, 'atom:title')),
            $homePage,
            $truncated,
        );
    }

    private function parseRdf(DOMXPath $xpath, string $feedUrl, int $maxItems): ParsedFeed
    {
        $channel = ($xpath->query('/rdf:RDF/rss1:channel') ?: null)?->item(0);
        $homePage = $channel instanceof DOMElement ? $this->absoluteUrl($this->childText($xpath, $channel, 'rss1:link'), $feedUrl) : null;
        $items = [];
        $truncated = false;

        foreach ($xpath->query('/rdf:RDF/rss1:item') ?: [] as $item) {
            if (count($items) >= $maxItems) {
                $truncated = true;

                break;
            }

            $about = $item instanceof DOMElement ? $item->getAttributeNS(self::RDF, 'about') : '';

            $candidate = $this->item(
                url: $this->absoluteUrl($this->childText($xpath, $item, 'rss1:link'), $feedUrl) ?? $this->absoluteUrl($about, $feedUrl),
                guid: $about !== '' ? $about : null,
                title: $this->childText($xpath, $item, 'rss1:title'),
                published: $this->childText($xpath, $item, 'dc:date'),
                summary: $this->childText($xpath, $item, 'rss1:description'),
            );

            if ($candidate !== null) {
                $items[] = $candidate;
            }
        }

        return new ParsedFeed(
            FeedFormat::Rdf,
            $items,
            $channel instanceof DOMElement ? $this->title($this->childText($xpath, $channel, 'rss1:title')) : null,
            $homePage,
            $truncated,
        );
    }

    private function parseJson(string $body, string $feedUrl, int $maxItems): ParsedFeed
    {
        try {
            $feed = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw FeedUnreadable::because('the JSON is malformed');
        }

        if (! is_array($feed) || ! is_string($feed['version'] ?? null) || ! str_starts_with($feed['version'], 'https://jsonfeed.org/version/')) {
            throw FeedUnreadable::because('the JSON document is not a JSON Feed');
        }

        if (! is_array($feed['items'] ?? null) || ! array_is_list($feed['items'])) {
            throw FeedUnreadable::because('the JSON Feed has no item list');
        }

        $homePage = $this->absoluteUrl($this->jsonString($feed, 'home_page_url'), $feedUrl);
        $items = [];
        $truncated = false;

        foreach ($feed['items'] as $entry) {
            if (count($items) >= $maxItems) {
                $truncated = true;

                break;
            }

            if (! is_array($entry)) {
                continue;
            }

            $guid = $entry['id'] ?? null;

            $candidate = $this->item(
                url: $this->absoluteUrl($this->jsonString($entry, 'url'), $feedUrl) ?? $this->absoluteUrl($this->jsonString($entry, 'external_url'), $feedUrl),
                guid: is_string($guid) || is_int($guid) ? (string) $guid : null,
                title: $this->jsonString($entry, 'title'),
                published: $this->jsonString($entry, 'date_published') ?? $this->jsonString($entry, 'date_modified'),
                summary: $this->jsonString($entry, 'summary') ?? $this->jsonString($entry, 'content_text') ?? $this->jsonString($entry, 'content_html'),
            );

            if ($candidate !== null) {
                $items[] = $candidate;
            }
        }

        return new ParsedFeed(
            FeedFormat::Json,
            $items,
            $this->title($this->jsonString($feed, 'title')),
            $homePage,
            $truncated,
        );
    }

    private function item(?string $url, ?string $guid, ?string $title, ?string $published, ?string $summary): ?FeedItem
    {
        if ($url === null) {
            return null;
        }

        $guid = $guid === null ? '' : trim(UntrustedText::clean($guid));

        return new FeedItem(
            url: $url,
            guid: $guid === '' ? $url : mb_substr($guid, 0, self::MAX_GUID_CHARACTERS),
            title: $this->title($title),
            publishedAt: $this->date($published),
            summary: $this->plainText($summary, self::MAX_SUMMARY_CHARACTERS),
        );
    }

    private function title(?string $value): ?string
    {
        return $this->plainText($value, self::MAX_TITLE_CHARACTERS);
    }

    /**
     * Feed text as plain text: markup and its scripts and styles removed,
     * entities decoded, invisible characters dropped, whitespace collapsed,
     * cut to a length.
     */
    private function plainText(?string $value, int $maxCharacters): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', ' ', $value);
        $value = (string) preg_replace('#<(br|p|div|li|h[1-6])\b[^>]*>#i', ' ', $value);
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = trim((string) preg_replace('/\s+/u', ' ', UntrustedText::clean($value)));

        if ($value === '') {
            return null;
        }

        return mb_strlen($value) > $maxCharacters ? rtrim(mb_substr($value, 0, $maxCharacters - 1)).'…' : $value;
    }

    private function date(?string $value): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '' || strlen($value) > 64) {
            return null;
        }

        try {
            return CarbonImmutable::parse(trim($value))->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function absoluteUrl(?string $value, string $base): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || strlen($value) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return null;
        }

        try {
            $resolved = UriResolver::resolve(new Uri($base), new Uri($value));
        } catch (Throwable) {
            return null;
        }

        if (! in_array(strtolower($resolved->getScheme()), ['http', 'https'], true) || $resolved->getHost() === '') {
            return null;
        }

        return (string) $resolved;
    }

    private function childText(DOMXPath $xpath, DOMNode $context, string $name): ?string
    {
        $node = ($xpath->query($name, $context) ?: null)?->item(0);

        if ($node === null) {
            return null;
        }

        $text = trim((string) $node->textContent);

        return $text === '' ? null : $text;
    }

    /**
     * An Atom element's alternate link: rel="alternate", or no rel at all.
     */
    private function atomLink(DOMXPath $xpath, DOMNode $context): ?string
    {
        $href = (string) $xpath->evaluate('string(atom:link[@rel="alternate" or not(@rel)][1]/@href)', $context);

        return $href === '' ? null : $href;
    }

    /**
     * @param  array<mixed>  $data
     */
    private function jsonString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
