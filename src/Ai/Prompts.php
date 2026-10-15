<?php

namespace ByteMinus\AiBlog\Ai;

/**
 * All prompts in one place so contributors can improve them without touching logic.
 * Every prompt that returns structured data asks for a single JSON object.
 */
final class Prompts
{
    public static function system(): string
    {
        $style = (string) config('ai-blog.ai.style_guide', '');

        return <<<TXT
        You are an expert editor and SEO specialist helping a human writer. You never fabricate facts,
        statistics or quotes. When unsure, say so inside the text as [verify: ...]. Write for people first,
        search engines second. House style: {$style}
        TXT;
    }

    public static function titles(string $title, string $excerpt, ?string $keyword): string
    {
        $kw = $keyword ? "Target keyword: \"{$keyword}\". Include it naturally in at least three titles." : '';

        return <<<TXT
        Suggest five alternative titles for this post, under 60 characters each, specific, no clickbait.
        {$kw}
        Current title: {$title}
        Opening: {$excerpt}
        Return JSON: {"titles": ["...", "..."]}
        TXT;
    }

    public static function metaDescription(string $title, string $excerpt, ?string $keyword, int $min, int $max): string
    {
        $kw = $keyword ? "It must contain the keyword \"{$keyword}\"." : '';

        return <<<TXT
        Write one meta description for this post, between {$min} and {$max} characters, one or two sentences,
        active voice, describes what the reader gets. {$kw}
        Title: {$title}
        Opening: {$excerpt}
        Return only the description text.
        TXT;
    }

    public static function extractKeyword(string $title, string $excerpt): string
    {
        return <<<TXT
        What single search phrase (2–4 words) is this post most likely trying to rank for?
        Title: {$title}
        Opening: {$excerpt}
        Return only the phrase, lowercase.
        TXT;
    }

    public static function brief(string $keyword, array $context): string
    {
        $existing = isset($context['existing_titles']) && $context['existing_titles']
            ? 'Posts this site already has (avoid duplicating, consider linking): '.implode('; ', $context['existing_titles'])
            : '';

        return <<<TXT
        Create a content brief for the keyword "{$keyword}".
        Identify the search intent (informational, commercial, transactional, navigational), the audience,
        a working title under 60 characters containing the keyword, an outline of 4–7 H2 headings in a logical order,
        the questions a reader with this query needs answered, and 5 related terms to include naturally.
        {$existing}
        Return JSON: {"intent": "...", "audience": "...", "working_title": "...", "outline": ["..."],
        "questions": ["..."], "related_terms": ["..."]}
        TXT;
    }

    public static function draft(Brief $brief, array $context): string
    {
        $outline = implode("\n", array_map(fn ($h) => "- {$h}", $brief->outline));
        $questions = implode("\n", array_map(fn ($q) => "- {$q}", $brief->questions));
        $terms = implode(', ', $brief->relatedTerms);
        $minWords = (int) config('ai-blog.seo.min_words', 600);

        return <<<TXT
        Write a complete blog post in Markdown from this brief. At least {$minWords} words. Use the outline as H2
        headings (##). Use the keyword "{$brief->keyword}" in the first paragraph and in at least one H2, without
        stuffing. Answer every question. Work in related terms where natural: {$terms}.
        Do not invent statistics, studies or quotes; mark anything needing a source as [verify: ...].
        End with a short FAQ section using "## Question?" headings.

        Intent: {$brief->intent}
        Audience: {$brief->audience}
        Working title: {$brief->workingTitle}
        Outline:
        {$outline}
        Questions to answer:
        {$questions}

        Return JSON: {"title": "...", "excerpt": "one sentence", "seo_description": "120–160 chars",
        "body": "markdown...", "image_prompt": "a prompt for a featured image"}
        TXT;
    }

    public static function rewrite(string $passage, string $instruction): string
    {
        return <<<TXT
        Rewrite the passage below. Instruction: {$instruction}
        Keep the meaning, keep any facts exactly as they are, keep Markdown formatting.
        Return only the rewritten passage.

        {$passage}
        TXT;
    }

    public static function anchor(string $excerpt, string $targetTitle, string $targetSummary): string
    {
        return <<<TXT
        We want to add an internal link from the source post to the target post.
        Pick a 2–5 word phrase that already exists in the source text and would make natural, descriptive
        anchor text for the target. If none exists, propose a short phrase that could be inserted.
        Target post title: {$targetTitle}
        Target summary: {$targetSummary}
        Source text:
        {$excerpt}
        Return only the anchor text.
        TXT;
    }

    public static function refresh(string $title, string $excerpt, \DateTimeInterface $publishedAt): string
    {
        $date = $publishedAt->format('F Y');

        return <<<TXT
        This post was published in {$date} and may be out of date. List 3–6 specific, concrete updates a writer
        should make — dates, versions, prices, tools that changed, sections to add or remove. Be specific to this
        post, not generic advice.
        Title: {$title}
        Opening: {$excerpt}
        Return JSON: {"suggestions": ["..."]}
        TXT;
    }

    public static function translate(string $title, string $body, string $locale, ?string $description): string
    {
        return <<<TXT
        Translate this blog post into the language with locale code "{$locale}". Preserve Markdown structure,
        links, code blocks and proper nouns. Adapt idioms; do not translate word-for-word.
        Title: {$title}
        Meta description: {$description}
        Body:
        {$body}
        Return JSON: {"title": "...", "seo_description": "...", "body": "..."}
        TXT;
    }
}
