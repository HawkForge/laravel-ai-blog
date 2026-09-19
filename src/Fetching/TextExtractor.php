<?php

declare(strict_types=1);

namespace AiBlog\Fetching;

use Carbon\CarbonImmutable;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Throwable;

/**
 * Turns a fetched document into bounded plain text plus the title, author
 * and publish date when the page states them.
 *
 * HTML is parsed with PHP's HTML5 parser, which never fetches anything. The
 * title, author and date come from the usual meta tags, JSON-LD and
 * `<time datetime>`. The text comes from `<article>`, else `<main>`, else the
 * body, after removing scripts, styles, embedded objects, forms, navigation
 * and anything hidden from readers (`hidden`, `aria-hidden`, inline
 * `display:none` or `visibility:hidden`), since hidden text is a favourite
 * place for instructions aimed at a model. Plain text, JSON and XML are kept
 * as they are. Either way the result is cleaned of control and invisible
 * characters and cut to the character limit.
 *
 * The result is still untrusted; see {@see ExtractedText::untrusted()}.
 */
final class TextExtractor
{
    public const DEFAULT_MAX_CHARACTERS = 20_000;

    private const METADATA_MAX_CHARACTERS = 300;

    /** Elements whose content is never reader-visible text. */
    private const REMOVED_ELEMENTS = [
        'script', 'style', 'noscript', 'template', 'iframe', 'frame', 'frameset', 'object', 'embed',
        'svg', 'math', 'canvas', 'form', 'button', 'select', 'textarea', 'input', 'nav', 'footer',
        'aside', 'dialog', 'head', 'link', 'meta',
    ];

    /** Elements that end a line of text. */
    private const BLOCK_ELEMENTS = [
        'address', 'article', 'blockquote', 'br', 'dd', 'div', 'dl', 'dt', 'figcaption', 'figure',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'li', 'main', 'ol', 'p', 'pre', 'section',
        'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'ul', 'details', 'summary',
    ];

    private const MAX_JSON_LD_BLOCKS = 10;

    public function extract(FetchedDocument $document, int $maxCharacters = self::DEFAULT_MAX_CHARACTERS): ExtractedText
    {
        $body = $this->toUtf8($document->body, $document->charset());

        if (! $document->isHtml()) {
            [$text, $truncated] = $this->bound($this->normaliseWhitespace(UntrustedText::clean($body)), $maxCharacters);

            return new ExtractedText($document->finalUrl, $text, truncated: $truncated);
        }

        $html = $this->parse($body);

        if ($html === null) {
            return new ExtractedText($document->finalUrl, '');
        }

        $title = $this->title($html);
        $author = $this->author($html);
        $publishedAt = $this->publishedAt($html);

        $this->removeInvisible($html);

        $root = $html->querySelector('article') ?? $html->querySelector('main') ?? $html->querySelector('[role="main"]') ?? $html->body;
        $text = $root === null ? '' : $this->text($root);

        [$text, $truncated] = $this->bound($this->normaliseWhitespace(UntrustedText::clean($text)), $maxCharacters);

        return new ExtractedText($document->finalUrl, $text, $title, $author, $publishedAt, $truncated);
    }

    private function parse(string $html): ?HTMLDocument
    {
        try {
            return HTMLDocument::createFromString($html, LIBXML_NOERROR | LIBXML_COMPACT, 'UTF-8');
        } catch (Throwable) {
            return null;
        }
    }

    private function toUtf8(string $body, ?string $charset): string
    {
        if ($charset === null && preg_match('/<meta[^>]+charset\s*=\s*["\']?([A-Za-z0-9._:-]+)/i', substr($body, 0, 4096), $match) === 1) {
            $charset = strtoupper($match[1]);
        }

        if ($charset !== null && $charset !== 'UTF-8' && $charset !== 'UTF8') {
            try {
                $converted = mb_convert_encoding($body, 'UTF-8', $charset);

                if (is_string($converted)) {
                    return $converted;
                }
            } catch (Throwable) {
                // An unknown charset name falls through to the UTF-8 repair below.
            }
        }

        return mb_check_encoding($body, 'UTF-8') ? $body : (string) mb_convert_encoding($body, 'UTF-8', 'UTF-8');
    }

    private function title(HTMLDocument $html): ?string
    {
        return $this->metaContent($html, ['meta[property="og:title"]', 'meta[name="twitter:title"]'])
            ?? $this->metadata($html->querySelector('title')?->textContent)
            ?? $this->metadata($html->querySelector('h1')?->textContent);
    }

    private function author(HTMLDocument $html): ?string
    {
        $author = $this->metaContent($html, ['meta[name="author"]', 'meta[property="article:author"]', 'meta[name="byl"]']);

        if ($author !== null && ! preg_match('#^https?://#i', $author)) {
            return $author;
        }

        foreach ($this->jsonLd($html) as $item) {
            $name = $this->jsonLdAuthor($item['author'] ?? null);

            if ($name !== null) {
                return $name;
            }
        }

        return $this->metadata($html->querySelector('[rel="author"]')?->textContent)
            ?? $this->metadata($html->querySelector('[itemprop="author"]')?->textContent);
    }

    private function publishedAt(HTMLDocument $html): ?CarbonImmutable
    {
        $candidates = [
            $this->metaContent($html, [
                'meta[property="article:published_time"]',
                'meta[name="article:published_time"]',
                'meta[itemprop="datePublished"]',
                'meta[name="date"]',
                'meta[name="pubdate"]',
                'meta[name="publish-date"]',
                'meta[name="dc.date"]',
                'meta[name="DC.date.issued"]',
            ]),
        ];

        foreach ($this->jsonLd($html) as $item) {
            $candidates[] = is_string($item['datePublished'] ?? null) ? $item['datePublished'] : null;
        }

        $candidates[] = $html->querySelector('time[datetime]')?->getAttribute('datetime');

        foreach ($candidates as $candidate) {
            $date = $this->date($candidate);

            if ($date !== null) {
                return $date;
            }
        }

        return null;
    }

    private function date(?string $value): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '' || strlen($value) > 64) {
            return null;
        }

        try {
            $date = CarbonImmutable::parse(trim($value));
        } catch (Throwable) {
            return null;
        }

        if ($date->year < 1990 || $date->isAfter(CarbonImmutable::now()->addDays(2))) {
            return null;
        }

        return $date->utc();
    }

    /**
     * @param  list<string>  $selectors
     */
    private function metaContent(HTMLDocument $html, array $selectors): ?string
    {
        foreach ($selectors as $selector) {
            $value = $this->metadata($html->querySelector($selector)?->getAttribute('content'));

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function metadata(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) preg_replace('/\s+/u', ' ', UntrustedText::clean($value)));

        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, self::METADATA_MAX_CHARACTERS);
    }

    /**
     * Article-like JSON-LD objects on the page, flattened out of `@graph`.
     *
     * @return list<array<string, mixed>>
     */
    private function jsonLd(HTMLDocument $html): array
    {
        $items = [];

        foreach ($html->querySelectorAll('script[type="application/ld+json"]') as $index => $script) {
            if ($index >= self::MAX_JSON_LD_BLOCKS) {
                break;
            }

            $decoded = json_decode((string) $script->textContent, true, 32);

            if (! is_array($decoded)) {
                continue;
            }

            $candidates = array_is_list($decoded) ? $decoded : [$decoded];

            if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
                $candidates = [...$candidates, ...array_values($decoded['@graph'])];
            }

            foreach ($candidates as $candidate) {
                if (is_array($candidate) && (isset($candidate['datePublished']) || isset($candidate['author']))) {
                    $items[] = $candidate;
                }
            }
        }

        return $items;
    }

    private function jsonLdAuthor(mixed $author): ?string
    {
        if (is_string($author)) {
            return $this->metadata($author);
        }

        if (is_array($author) && array_is_list($author)) {
            return $author === [] ? null : $this->jsonLdAuthor($author[0]);
        }

        if (is_array($author) && is_string($author['name'] ?? null)) {
            return $this->metadata($author['name']);
        }

        return null;
    }

    private function removeInvisible(HTMLDocument $html): void
    {
        $selectors = [
            implode(',', self::REMOVED_ELEMENTS),
            '[hidden]',
            '[aria-hidden="true"]',
            '[style*="display:none" i]',
            '[style*="display: none" i]',
            '[style*="visibility:hidden" i]',
            '[style*="visibility: hidden" i]',
        ];

        foreach ($selectors as $selector) {
            foreach (iterator_to_array($html->querySelectorAll($selector)) as $element) {
                $element->remove();
            }
        }
    }

    /**
     * The visible text under an element, one line per block element. Walks
     * the tree with an explicit stack, so a deeply nested page cannot run out
     * of call stack.
     */
    private function text(Element $root): string
    {
        $pieces = [];
        /** @var list<array{0: Node|null, 1: bool}> $stack */
        $stack = [[$root, false]];

        while ($stack !== []) {
            [$node, $leaving] = array_pop($stack);

            if ($leaving) {
                $pieces[] = "\n";

                continue;
            }

            if ($node === null) {
                continue;
            }

            if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
                $pieces[] = (string) $node->textContent;

                continue;
            }

            if (! $node instanceof Element) {
                continue;
            }

            $isBlock = in_array(strtolower($node->localName), self::BLOCK_ELEMENTS, true);

            if ($isBlock) {
                $pieces[] = "\n";
                $stack[] = [null, true];
            }

            $children = iterator_to_array($node->childNodes);

            for ($index = count($children) - 1; $index >= 0; $index--) {
                $stack[] = [$children[$index], false];
            }
        }

        return implode('', $pieces);
    }

    private function normaliseWhitespace(string $text): string
    {
        $text = (string) preg_replace('/[ \t\x{00A0}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]+/u', ' ', $text);
        $text = (string) preg_replace('/ *\n */', "\n", $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function bound(string $text, int $maxCharacters): array
    {
        if (mb_strlen($text) <= $maxCharacters) {
            return [$text, false];
        }

        return [rtrim(mb_substr($text, 0, $maxCharacters)), true];
    }
}
