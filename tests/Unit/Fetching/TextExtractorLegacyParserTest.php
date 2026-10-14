<?php

declare(strict_types=1);

namespace AiBlog\Tests\Unit\Fetching;

use AiBlog\Fetching\LegacyHtmlDocument;
use AiBlog\Fetching\TextExtractor;

/**
 * Runs every TextExtractorTest case against the libxml2 parser that PHP older
 * than 8.4 falls back to, so both parsers are held to the same behaviour.
 */
class TextExtractorLegacyParserTest extends TextExtractorTest
{
    protected function extractor(): TextExtractor
    {
        return new TextExtractor(useLegacyParser: true);
    }

    /**
     * libxml2 stops at a fixed nesting depth (about 256) instead of recursing, and
     * gives back nothing for a page nested deeper. Text it drops is text no model
     * ever sees, so a pathological page fails safe.
     */
    public function test_a_deeply_nested_page_does_not_exhaust_the_stack(): void
    {
        $nested = fn (int $depth): string => '<html><body>'.str_repeat('<div>', $depth).'deep'.str_repeat('</div>', $depth).'</body></html>';

        $this->assertStringContainsString('deep', $this->extractor()->extract($this->document($nested(200)))->text);
        $this->assertSame('', $this->extractor()->extract($this->document($nested(5000)))->text);
    }

    public function test_the_fallback_understands_the_selectors_the_extractor_uses(): void
    {
        $html = LegacyHtmlDocument::fromString(
            '<html><head><title>T</title><meta name="Author" content="A"><meta property="og:title" content="O"></head>'
            .'<body><p id="a" STYLE="Display: None">x</p><p hidden>y</p><time datetime="2026-01-02">d</time></body></html>'
        );

        $this->assertNotNull($html);
        $this->assertSame('T', $html->querySelector('title')?->textContent);
        $this->assertSame('O', $html->querySelector('meta[property="og:title"]')?->getAttribute('content'));
        $this->assertCount(1, $html->querySelectorAll('[style*="display: none" i]'));
        $this->assertCount(1, $html->querySelectorAll('[hidden]'));
        $this->assertCount(2, $html->querySelectorAll('title, time[datetime]'));
        $this->assertNull($html->querySelector('meta[name="missing"]'));
        $this->assertNotNull($html->body());
    }

    public function test_the_fallback_refuses_selectors_it_does_not_understand(): void
    {
        $html = LegacyHtmlDocument::fromString('<p>x</p>');

        $this->expectException(\InvalidArgumentException::class);

        $html?->querySelector('div > p');
    }
}
