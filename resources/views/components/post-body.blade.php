@props(['post'])
<div {{ $attributes->merge(['class' => 'ai-blog-body']) }}>
    {!! \AiBlog\Support\Markdown::toSafeHtml($post->bodyText()) !!}
</div>
