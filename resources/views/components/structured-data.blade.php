@props(['post'])
<script type="application/ld+json">{!! json_encode($post->structuredData(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@php($faq = \ByteMinus\AiBlog\Seo\StructuredData::faqFromBody($post->bodyText()))
@if ($faq)
<script type="application/ld+json">{!! json_encode($faq, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endif
