@props(['post'])
@php
    $url = \AiBlog\Seo\StructuredData::urlFor($post);
    $image = \AiBlog\Seo\StructuredData::imageUrl($post);
    $imageAlt = $post->featured_image_alt ?? null;
    $site = config('ai-blog.seo.site_name');
    $description = $post->seoDescription() ?: trim((string) ($post->excerpt ?? ''));
@endphp
@if ($description !== '')<meta name="description" content="{{ $description }}">@endif
<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
@if ($url)<link rel="canonical" href="{{ $url }}">@endif
@if (\Illuminate\Support\Facades\Route::has('ai-blog.feed'))<link rel="alternate" type="application/rss+xml" title="{{ $site }}" href="{{ route('ai-blog.feed') }}">@endif
<meta property="og:type" content="article">
@if ($site)<meta property="og:site_name" content="{{ $site }}">@endif
<meta property="og:title" content="{{ $post->seoTitle() }}">
@if ($description !== '')<meta property="og:description" content="{{ $description }}">@endif
@if ($url)<meta property="og:url" content="{{ $url }}">@endif
@if ($image)<meta property="og:image" content="{{ $image }}">@endif
@if ($image && $imageAlt)<meta property="og:image:alt" content="{{ $imageAlt }}">@endif
@if ($post->published_at)<meta property="article:published_time" content="{{ $post->published_at->toIso8601String() }}">@endif
@if ($post->updated_at)<meta property="article:modified_time" content="{{ $post->updated_at->toIso8601String() }}">@endif
<meta name="twitter:card" content="{{ $image ? 'summary_large_image' : 'summary' }}">
<meta name="twitter:title" content="{{ $post->seoTitle() }}">
@if ($description !== '')<meta name="twitter:description" content="{{ $description }}">@endif
@if ($image)<meta name="twitter:image" content="{{ $image }}">@endif
@if ($image && $imageAlt)<meta name="twitter:image:alt" content="{{ $imageAlt }}">@endif
