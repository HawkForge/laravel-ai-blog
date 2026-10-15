<?php

namespace ByteMinus\AiBlog\Ai;

use ByteMinus\AiBlog\Ai\Contracts\Assistant;
use ByteMinus\AiBlog\Seo\Text;
use Laravel\Ai\AnonymousAgent;
use RuntimeException;

/**
 * Assistant backed by the official laravel/ai SDK (any provider it supports).
 *
 * Every provider call goes through complete() — the single integration point
 * with the SDK — so swapping providers or SDK versions touches one method.
 */
class LaravelAiAssistant implements Assistant
{
    public function suggestTitles(string $title, string $body, ?string $keyword = null): array
    {
        $json = $this->complete(
            Prompts::system(),
            Prompts::titles($title, Text::firstParagraph($body, 300), $keyword)
        );

        return array_values(array_filter(array_map('strval', $this->json($json)['titles'] ?? [])));
    }

    public function writeMetaDescription(string $title, string $body, ?string $keyword = null): string
    {
        $max = (int) config('ai-blog.seo.description_max', 160);
        $min = (int) config('ai-blog.seo.description_min', 120);
        $text = $this->complete(
            Prompts::system(),
            Prompts::metaDescription($title, Text::firstParagraph($body, 300), $keyword, $min, $max)
        );

        return trim($text, " \n\"");
    }

    public function extractKeyword(string $title, string $body): string
    {
        $text = $this->complete(Prompts::system(), Prompts::extractKeyword($title, Text::firstParagraph($body, 400)));

        return mb_strtolower(trim($text, " \n\""));
    }

    public function brief(string $keyword, array $context = []): Brief
    {
        $json = $this->complete(Prompts::system(), Prompts::brief($keyword, $context));

        return Brief::fromArray(['keyword' => $keyword] + $this->json($json));
    }

    public function draft(Brief $brief, array $context = []): Draft
    {
        $json = $this->complete(Prompts::system(), Prompts::draft($brief, $context));

        return Draft::fromArray(['keyword' => $brief->keyword] + $this->json($json));
    }

    public function rewrite(string $passage, string $instruction): string
    {
        return trim($this->complete(Prompts::system(), Prompts::rewrite($passage, $instruction)));
    }

    public function proposeAnchor(string $body, string $targetTitle, string $targetSummary): string
    {
        $text = $this->complete(
            Prompts::system(),
            Prompts::anchor(Text::firstParagraph($body, 500), $targetTitle, $targetSummary)
        );

        return trim($text, " \n\"");
    }

    public function refreshSuggestions(string $title, string $body, \DateTimeInterface $publishedAt): array
    {
        $json = $this->complete(
            Prompts::system(),
            Prompts::refresh($title, Text::firstParagraph($body, 600), $publishedAt)
        );

        return array_values(array_map('strval', $this->json($json)['suggestions'] ?? []));
    }

    public function translate(string $title, string $body, string $locale, ?string $description = null): array
    {
        $json = $this->complete(Prompts::system(), Prompts::translate($title, $body, $locale, $description));
        $data = $this->json($json);

        return [
            'title' => (string) ($data['title'] ?? $title),
            'body' => (string) ($data['body'] ?? $body),
            'seo_description' => $data['seo_description'] ?? $description,
        ];
    }

    // ------------------------------------------------------------------ SDK

    /**
     * The ONLY place that talks to laravel/ai. Returns the model's text.
     *
     * Override this method (or bind your own Assistant) to use a different SDK.
     */
    protected function complete(string $system, string $prompt): string
    {
        if (! class_exists(AnonymousAgent::class)) {
            throw new RuntimeException(
                'Laravel AI Blog\'s AI features need the laravel/ai SDK: composer require laravel/ai. '
                .'Or set ai-blog.ai.enabled=false to run without AI.'
            );
        }

        $provider = config('ai-blog.ai.provider');
        $model = config('ai-blog.ai.model');

        // A one-off agent with the system prompt as its instructions. Provider and model
        // fall back to the SDK's defaults when null.
        $response = (new AnonymousAgent($system, [], []))->prompt($prompt, provider: $provider, model: $model);

        return (string) $response;
    }

    /** Parse a JSON object out of a model response, tolerating code fences and preamble. */
    protected function json(string $text): array
    {
        $text = trim($text);
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $text, $m)) {
            $text = $m[1];
        } elseif (preg_match('/(\{.*\})/s', $text, $m)) {
            $text = $m[1];
        }

        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : [];
    }
}
