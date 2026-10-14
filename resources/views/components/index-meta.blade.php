@props(['posts', 'heading' => null])
@php
    $site = config('ai-blog.seo.site_name');
    $page = $posts->currentPage();
    $url = $page > 1 ? $posts->url($page) : $posts->path();
    $description = config('ai-blog.seo.description') ?: ($heading ? "{$heading} — posts from {$site}" : "Latest posts from {$site}");
    $title = ($heading ?? 'Blog').($page > 1 ? " — Page {$page}" : '').' — '.$site;
@endphp
<meta name="description" content="{{ $description }}">
<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
<link rel="canonical" href="{{ $url }}">
@if ($posts->previousPageUrl())<link rel="prev" href="{{ $posts->previousPageUrl() }}">@endif
@if ($posts->nextPageUrl())<link rel="next" href="{{ $posts->nextPageUrl() }}">@endif
<link rel="alternate" type="application/rss+xml" title="{{ $site }}" href="{{ route('ai-blog.feed') }}">
<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ $site }}">
<meta property="og:title" content="{{ $title }}">
<meta property="og:description" content="{{ $description }}">
<meta property="og:url" content="{{ $url }}">
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="{{ $title }}">
<meta name="twitter:description" content="{{ $description }}">
