@props(['post'])
<div {{ $attributes->merge(['class' => 'ai-blog-body']) }}>
    {!! \ByteMinus\AiBlog\Support\Markdown::toSafeHtml($post->bodyText()) !!}
</div>
