<?php

namespace ByteMinus\AiBlog\Ai;

use ByteMinus\AiBlog\Ai\Contracts\Assistant;
use ByteMinus\AiBlog\Seo\Text;

/**
 * Deterministic assistant for tests and for running without an AI provider.
 * Used automatically when ai-blog.ai.enabled is false.
 */
class FakeAssistant implements Assistant
{
    public array $calls = [];

    public function suggestTitles(string $title, string $body, ?string $keyword = null): array
    {
        $this->calls[] = __FUNCTION__;

        return array_filter([
            $title,
            $keyword ? ucfirst($keyword).': '.$title : null,
            'How to '.lcfirst($title),
        ]);
    }

    public function writeMetaDescription(string $title, string $body, ?string $keyword = null): string
    {
        $this->calls[] = __FUNCTION__;
        $base = Text::firstParagraph($body, 22) ?: $title;
        $desc = $keyword && ! Text::contains($base, $keyword) ? "{$keyword}: {$base}" : $base;

        return mb_substr(rtrim($desc, '.').'.', 0, (int) config('ai-blog.seo.description_max', 160));
    }

    public function extractKeyword(string $title, string $body): string
    {
        $this->calls[] = __FUNCTION__;
        $tf = Text::termFrequency($title.' '.$body);
        arsort($tf);

        return (string) (array_key_first($tf) ?? mb_strtolower($title));
    }

    public function brief(string $keyword, array $context = []): Brief
    {
        $this->calls[] = __FUNCTION__;

        return new Brief(
            keyword: $keyword,
            intent: 'informational',
            audience: 'readers searching for '.$keyword,
            workingTitle: ucfirst($keyword).': a practical guide',
            outline: ['What is '.$keyword.'?', 'Why '.$keyword.' matters', 'How to get started', 'Common mistakes', 'FAQ'],
            questions: ['What is '.$keyword.'?', 'How do I start with '.$keyword.'?'],
            relatedTerms: [],
        );
    }

    public function draft(Brief $brief, array $context = []): Draft
    {
        $this->calls[] = __FUNCTION__;
        $body = collect($brief->outline)
            ->map(fn ($h) => "## {$h}\n\nThis section covers {$h} in the context of {$brief->keyword}.")
            ->implode("\n\n");

        return new Draft(
            title: $brief->workingTitle,
            body: "Introduction to {$brief->keyword}.\n\n".$body,
            excerpt: "A practical guide to {$brief->keyword}.",
            seoDescription: $this->writeMetaDescription($brief->workingTitle, $body, $brief->keyword),
            keyword: $brief->keyword,
        );
    }

    public function rewrite(string $passage, string $instruction): string
    {
        $this->calls[] = __FUNCTION__;

        return $passage;
    }

    public function proposeAnchor(string $body, string $targetTitle, string $targetSummary): string
    {
        $this->calls[] = __FUNCTION__;

        return $targetTitle;
    }

    public function refreshSuggestions(string $title, string $body, \DateTimeInterface $publishedAt): array
    {
        $this->calls[] = __FUNCTION__;

        return ['Update any dates, versions and statistics mentioned.', 'Add links to newer related posts.'];
    }

    public function translate(string $title, string $body, string $locale, ?string $description = null): array
    {
        $this->calls[] = __FUNCTION__;

        return ['title' => $title, 'body' => $body, 'seo_description' => $description];
    }
}
