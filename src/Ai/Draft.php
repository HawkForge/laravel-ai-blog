<?php

namespace AiBlog\Ai;

final class Draft
{
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly string $excerpt,
        public readonly string $seoDescription,
        public readonly ?string $keyword = null,
        public readonly ?string $imagePrompt = null,
    ) {}

    public static function fromArray(array $a): self
    {
        return new self(
            title: (string) ($a['title'] ?? ''),
            body: (string) ($a['body'] ?? ''),
            excerpt: (string) ($a['excerpt'] ?? ''),
            seoDescription: (string) ($a['seo_description'] ?? ''),
            keyword: $a['keyword'] ?? null,
            imagePrompt: $a['image_prompt'] ?? null,
        );
    }

    /** Attributes ready for Post::create(). Always a draft; never published. */
    public function toPostAttributes(): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'excerpt' => $this->excerpt,
            'seo_description' => $this->seoDescription,
            'target_keyword' => $this->keyword,
            'status' => 'draft',
            'ai_assistance' => 'drafted',
            'meta' => array_filter(['image_prompt' => $this->imagePrompt]),
        ];
    }
}
