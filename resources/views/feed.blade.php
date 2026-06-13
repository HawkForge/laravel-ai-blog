<?php echo '<?xml version="1.0" encoding="UTF-8"?>'; ?>

<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
<channel>
    <title>{{ config('ai-blog.seo.site_name') }}</title>
    <link>{{ route('ai-blog.index') }}</link>
    <atom:link href="{{ route('ai-blog.feed') }}" rel="self" type="application/rss+xml" />
    <description>{{ config('ai-blog.seo.site_name') }} blog</description>
    @foreach ($posts as $post)
    <item>
        <title>{{ $post->title }}</title>
        <link>{{ route('ai-blog.show', $post->slug) }}</link>
        <guid>{{ route('ai-blog.show', $post->slug) }}</guid>
        <pubDate>{{ $post->published_at?->toRssString() }}</pubDate>
        <description>{{ $post->excerpt ?? $post->seoDescription() }}</description>
    </item>
    @endforeach
</channel>
</rss>
