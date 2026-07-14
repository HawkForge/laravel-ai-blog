<?php

namespace AiBlog\Ai\Contracts;

use AiBlog\Ai\Brief;
use AiBlog\Ai\Draft;

/**
 * Everything the package asks an AI to do. Implement this to use any provider;
 * the default implementation uses the official laravel/ai SDK.
 *
 * Every method assists a human writer. None of them publish.
 */
interface Assistant
{
    /** Three to five alternative titles for a post. @return array<int, string> */
    public function suggestTitles(string $title, string $body, ?string $keyword = null): array;

    /** A meta description within the configured length, containing the keyword. */
    public function writeMetaDescription(string $title, string $body, ?string $keyword = null): string;

    /** Extract the most likely target keyword from existing content. */
    public function extractKeyword(string $title, string $body): string;

    /** Research a keyword into a brief: intent, audience, outline, questions to answer. */
    public function brief(string $keyword, array $context = []): Brief;

    /** Turn a brief into a full Markdown draft. */
    public function draft(Brief $brief, array $context = []): Draft;

    /** Rewrite a passage with an instruction ("tighten", "simpler", "more formal"...). */
    public function rewrite(string $passage, string $instruction): string;

    /** Given a post and a target, propose anchor text and where to place a link. */
    public function proposeAnchor(string $body, string $targetTitle, string $targetSummary): string;

    /** Propose specific updates to refresh a stale post. @return array<int, string> */
    public function refreshSuggestions(string $title, string $body, \DateTimeInterface $publishedAt): array;

    /** Translate a post. Returns ['title' => ..., 'body' => ..., 'seo_description' => ...]. */
    public function translate(string $title, string $body, string $locale, ?string $description = null): array;
}
