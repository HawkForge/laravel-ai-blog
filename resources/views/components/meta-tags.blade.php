@props(['post'])
<meta name="description" content="{{ $post->seoDescription() }}">
@if ($post->canonical_url ?? null)<link rel="canonical" href="{{ $post->canonical_url }}">@endif
<meta property="og:type" content="article">
<meta property="og:title" content="{{ $post->seoTitle() }}">
<meta property="og:description" content="{{ $post->seoDescription() }}">
@if ($post->featured_image ?? null)<meta property="og:image" content="{{ $post->featured_image }}">@endif
@if ($post->published_at)<meta property="article:published_time" content="{{ $post->published_at->toIso8601String() }}">@endif
<meta name="twitter:card" content="{{ ($post->featured_image ?? null) ? 'summary_large_image' : 'summary' }}">
<meta name="twitter:title" content="{{ $post->seoTitle() }}">
<meta name="twitter:description" content="{{ $post->seoDescription() }}">
