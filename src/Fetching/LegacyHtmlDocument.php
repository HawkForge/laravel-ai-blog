<?php

declare(strict_types=1);

namespace AiBlog\Fetching;

use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;

/**
 * Stands in for PHP 8.4's `Dom\HTMLDocument` on older PHP, so {@see TextExtractor}
 * runs everywhere. It offers only what the extractor uses, on top of libxml2's
 * HTML parser: `querySelector()`, `querySelectorAll()` and the body.
 *
 * It understands the selector shapes the extractor needs and nothing else: a
 * comma-separated list of an optional tag name followed by `[attr]`,
 * `[attr="value"]` or `[attr*="value"]`, each optionally ending in ` i`.
 * PHP 8.4 and later use the spec-compliant HTML5 parser instead.
 *
 * @internal
 */
final class LegacyHtmlDocument
{
    private const UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private const LOWER = 'abcdefghijklmnopqrstuvwxyz';

    private function __construct(private readonly DOMXPath $xpath) {}

    /**
     * @param  string  $html  already converted to UTF-8
     */
    public static function fromString(string $html): ?self
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            // The markup is UTF-8 already: say so, and drop any <meta charset> that would argue.
            $html = preg_replace('/<meta\b[^>]*?\bcharset\s*=[^>]*>/i', '', $html) ?? $html;
            $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded) {
            return null;
        }

        foreach (iterator_to_array($document->childNodes) as $child) {
            if ($child->nodeType === XML_PI_NODE) {
                $document->removeChild($child);
            }
        }

        return new self(new DOMXPath($document));
    }

    public function body(): ?DOMElement
    {
        return $this->querySelector('body');
    }

    public function querySelector(string $selector): ?DOMElement
    {
        return $this->querySelectorAll($selector)[0] ?? null;
    }

    /**
     * @return list<DOMElement>
     */
    public function querySelectorAll(string $selector): array
    {
        $nodes = $this->xpath->query($this->toXPath($selector));
        $elements = [];

        foreach ($nodes === false ? [] : $nodes as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    private function toXPath(string $selector): string
    {
        $paths = [];

        foreach (explode(',', $selector) as $part) {
            if (preg_match('/^\s*([a-z][a-z0-9]*)?((?:\[[^\]]+\])*)\s*$/i', $part, $simple) !== 1) {
                throw new InvalidArgumentException("Unsupported selector [{$part}].");
            }

            $attributes = $simple[2];
            $matched = preg_match_all('/\[\s*([a-z][a-z-]*)\s*(?:(\*?=)\s*"([^"]*)"(?:\s+(i))?)?\s*\]/i', $attributes, $found, PREG_SET_ORDER);

            if ($matched !== substr_count($attributes, '[')) {
                throw new InvalidArgumentException("Unsupported selector [{$part}].");
            }

            $conditions = array_map(fn (array $attribute): string => $this->condition($attribute), $found);

            $paths[] = '//'.($simple[1] !== '' ? strtolower($simple[1]) : '*').implode('', array_map(fn (string $c): string => "[{$c}]", $conditions));
        }

        return implode('|', $paths);
    }

    /**
     * @param  array<int, string>  $attribute  the pieces of one `[name op "value" i]`
     */
    private function condition(array $attribute): string
    {
        $name = '@'.strtolower($attribute[1]);

        if (($attribute[2] ?? '') === '') {
            return $name;
        }

        $value = $attribute[3];
        $ignoreCase = ($attribute[4] ?? '') !== '';

        if ($ignoreCase) {
            $name = 'translate('.$name.', '.$this->literal(self::UPPER).', '.$this->literal(self::LOWER).')';
            $value = strtolower($value);
        }

        return $attribute[2] === '*='
            ? 'contains('.$name.', '.$this->literal($value).')'
            : $name.'='.$this->literal($value);
    }

    private function literal(string $value): string
    {
        if (! str_contains($value, '"')) {
            return '"'.$value.'"';
        }

        if (! str_contains($value, "'")) {
            return "'".$value."'";
        }

        throw new InvalidArgumentException('A selector value cannot hold both kinds of quote.');
    }
}
