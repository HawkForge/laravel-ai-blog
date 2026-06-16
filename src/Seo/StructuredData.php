<?php

namespace AiBlog\Seo;

final class StructuredData
{
    /** JSON-LD BlogPosting. Pass the result to json_encode() inside a <script type="application/ld+json">. */
    public static function blogPosting(object $post, ?string $url = null): array
    {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            'headline' => $post->seoTitle(),
            'description' => $post->seoDescription() ?: null,
            'wordCount' => Text::wordCount($post->bodyText()),
            'datePublished' => $post->published_at?->toIso8601String(),
            'dateModified' => $post->updated_at?->toIso8601String(),
            'image' => $post->featured_image ?? null,
            'keywords' => $post->targetKeyword(),
            'mainEntityOfPage' => $url ? ['@type' => 'WebPage', '@id' => $url] : null,
        ];

        if (isset($post->author) && $post->author) {
            $data['author'] = [
                '@type' => 'Person',
                'name' => $post->author->name ?? 'Unknown',
            ];
        }

        return array_filter($data, fn ($v) => $v !== null && $v !== '');
    }

    /** JSON-LD FAQPage auto-detected from "## Question?" headings followed by a paragraph. */
    public static function faqFromBody(string $body): ?array
    {
        $entities = [];
        if (preg_match_all('/^##\s*(.+\?)\s*\n+([^#\n][^\n]*(?:\n(?!#)[^\n]*)*)/m', $body, $m, PREG_SET_ORDER)) {
            foreach ($m as $faq) {
                $entities[] = [
                    '@type' => 'Question',
                    'name' => trim($faq[1]),
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => Text::plain($faq[2])],
                ];
            }
        }

        if ($entities === []) {
            return null;
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $entities,
        ];
    }
}
