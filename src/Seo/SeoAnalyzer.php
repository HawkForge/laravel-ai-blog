<?php

namespace ByteMinus\AiBlog\Seo;

use ByteMinus\AiBlog\Concerns\HasBlogSeo;

/**
 * On-page SEO analysis. Returns a weighted score and plain-English fixes.
 * Deterministic and dependency-free; the AI layer can turn fixes into rewrites.
 *
 * @see HasBlogSeo
 */
class SeoAnalyzer
{
    /** @param object $post Any model using HasBlogSeo */
    public function analyze(object $post): SeoReport
    {
        $title = (string) $post->{$post->getTitleField()};
        $seoTitle = $post->seoTitle();
        $description = $post->seoDescription();
        $body = $post->bodyText();
        $slug = (string) ($post->{$post->getSlugField()} ?? '');
        $keyword = $post->targetKeyword();

        $cfg = config('ai-blog.seo', []);
        $titleMax = (int) ($cfg['title_max'] ?? 60);
        $descMin = (int) ($cfg['description_min'] ?? 120);
        $descMax = (int) ($cfg['description_max'] ?? 160);
        $minWords = (int) ($cfg['min_words'] ?? 600);

        $checks = [];

        // --- Title
        $len = mb_strlen($seoTitle);
        $checks[] = $len > 0 && $len <= $titleMax
            ? SeoCheck::pass('title_length', 'Title length', 10)
            : SeoCheck::fail('title_length', 'Title length', 10,
                $len === 0 ? 'Add a title.' : "Shorten the title to {$titleMax} characters or fewer (currently {$len}).");

        // --- Meta description
        $dlen = mb_strlen($description);
        $checks[] = $dlen >= $descMin && $dlen <= $descMax
            ? SeoCheck::pass('description_length', 'Meta description length', 10)
            : SeoCheck::fail('description_length', 'Meta description length', 10,
                $dlen === 0
                    ? "Write a meta description of {$descMin}–{$descMax} characters."
                    : "Adjust the meta description to {$descMin}–{$descMax} characters (currently {$dlen}).");

        // --- Word count
        $words = Text::wordCount($body);
        $checks[] = $words >= $minWords
            ? SeoCheck::pass('word_count', 'Content length', 10)
            : SeoCheck::fail('word_count', 'Content length', 10,
                "Expand the post to at least {$minWords} words (currently {$words}).");

        // --- Headings
        $h2s = Text::headings($body, 2);
        $checks[] = count($h2s) > 0
            ? SeoCheck::pass('has_h2', 'Uses H2 subheadings', 8)
            : SeoCheck::fail('has_h2', 'Uses H2 subheadings', 8, 'Break the post into sections with H2 subheadings.');

        // --- Images
        $missingAlt = Text::imagesMissingAlt($body);
        $checks[] = $missingAlt === []
            ? SeoCheck::pass('image_alt', 'Images have alt text', 6)
            : SeoCheck::fail('image_alt', 'Images have alt text', 6,
                count($missingAlt).' image(s) are missing alt text.');

        // --- Links
        $links = Text::linkCount($body);
        $checks[] = $links >= 2
            ? SeoCheck::pass('links', 'Links to other content', 8)
            : SeoCheck::fail('links', 'Links to other content', 8,
                'Add at least two links — internal links to related posts count most.');

        // --- Readability
        $ease = Text::readingEase($body);
        $checks[] = $words === 0 || $ease >= 50
            ? SeoCheck::pass('readability', 'Readability', 6)
            : SeoCheck::fail('readability', 'Readability', 6,
                "Simplify sentences; reading ease is {$ease} (aim for 50+).");

        // --- Keyword checks (only meaningful when a target keyword is set)
        if ($keyword === null) {
            $checks[] = SeoCheck::fail('keyword_set', 'Target keyword', 10,
                'Set a target keyword so the analyzer can check where it appears.');
        } else {
            $checks[] = SeoCheck::pass('keyword_set', 'Target keyword', 4);

            $checks[] = Text::contains($seoTitle, $keyword)
                ? SeoCheck::pass('keyword_in_title', 'Keyword in title', 10)
                : SeoCheck::fail('keyword_in_title', 'Keyword in title', 10,
                    "Include \"{$keyword}\" in the title.");

            $checks[] = Text::contains($description, $keyword)
                ? SeoCheck::pass('keyword_in_description', 'Keyword in meta description', 6)
                : SeoCheck::fail('keyword_in_description', 'Keyword in meta description', 6,
                    "Mention \"{$keyword}\" in the meta description.");

            $slugKw = str_replace(' ', '-', mb_strtolower($keyword));
            $checks[] = $slug !== '' && str_contains($slug, $slugKw)
                ? SeoCheck::pass('keyword_in_slug', 'Keyword in URL slug', 6)
                : SeoCheck::fail('keyword_in_slug', 'Keyword in URL slug', 6,
                    "Use \"{$slugKw}\" in the slug.");

            $checks[] = Text::contains(Text::firstParagraph($body), $keyword)
                ? SeoCheck::pass('keyword_early', 'Keyword in first 100 words', 8)
                : SeoCheck::fail('keyword_early', 'Keyword in first 100 words', 8,
                    "Use \"{$keyword}\" within the first 100 words.");

            $inH2 = collect($h2s)->contains(fn ($h) => Text::contains($h, $keyword));
            $checks[] = $inH2
                ? SeoCheck::pass('keyword_in_h2', 'Keyword in an H2', 6)
                : SeoCheck::fail('keyword_in_h2', 'Keyword in an H2', 6,
                    "Add \"{$keyword}\" to at least one H2.");
        }

        return new SeoReport($this->score($checks), $checks);
    }

    /** @param array<int, SeoCheck> $checks */
    private function score(array $checks): int
    {
        $total = array_sum(array_map(fn (SeoCheck $c) => $c->weight, $checks));
        if ($total === 0) {
            return 0;
        }
        $earned = array_sum(array_map(fn (SeoCheck $c) => $c->passed ? $c->weight : 0, $checks));

        return (int) round($earned / $total * 100);
    }
}
