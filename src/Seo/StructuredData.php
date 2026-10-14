<?php

namespace AiBlog\Seo;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

final class StructuredData
{
    /**
     * The address search engines should treat as this post's own: its `canonical_url`
     * when set, otherwise its blog route. Null when neither is known.
     */
    public static function urlFor(object $post): ?string
    {
        $canonical = $post->canonical_url ?? null;

        if (is_string($canonical) && $canonical !== '') {
            return $canonical;
        }

        $slug = method_exists($post, 'getSlugField') ? ($post->{$post->getSlugField()} ?? null) : ($post->slug ?? null);

        if (! is_string($slug) || $slug === '' || ! Route::has('ai-blog.show')) {
            return null;
        }

        return route('ai-blog.show', $slug);
    }

    /** The featured image as an absolute URL, which is what social crawlers and rich results need. */
    public static function imageUrl(object $post): ?string
    {
        $image = $post->featured_image ?? null;

        if (! is_string($image) || $image === '') {
            return null;
        }

        return Str::startsWith($image, ['http://', 'https://']) ? $image : url($image);
    }

    /** JSON-LD BlogPosting. Pass the result to json_encode() inside a <script type="application/ld+json">. */
    public static function blogPosting(object $post, ?string $url = null): array
    {
        $url ??= self::urlFor($post);
        $site = config('ai-blog.seo.site_name');

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            'headline' => $post->seoTitle(),
            'description' => $post->seoDescription() ?: null,
            'wordCount' => Text::wordCount($post->bodyText()),
            'datePublished' => $post->published_at?->toIso8601String(),
            'dateModified' => $post->updated_at?->toIso8601String(),
            'image' => self::imageUrl($post),
            'keywords' => $post->targetKeyword(),
            'url' => $url,
            'mainEntityOfPage' => $url ? ['@type' => 'WebPage', '@id' => $url] : null,
            'publisher' => is_string($site) && $site !== '' ? ['@type' => 'Organization', 'name' => $site] : null,
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
