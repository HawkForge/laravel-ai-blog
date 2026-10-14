@extends(config('ai-blog.routes.layout', 'layouts.app'))

@section('title', ($heading ?? 'Blog').($posts->currentPage() > 1 ? ' — Page '.$posts->currentPage() : '').' — '.config('ai-blog.seo.site_name'))

@push('head')
    <x-ai-blog::index-meta :posts="$posts" :heading="$heading ?? null" />
@endpush

@section('content')
<section class="ai-blog ai-blog-index">
    <h1>{{ $heading ?? 'Blog' }}</h1>

    @forelse ($posts as $post)
        <article class="ai-blog-card">
            <h2><a href="{{ route('ai-blog.show', $post->slug) }}">{{ $post->title }}</a></h2>
            <time datetime="{{ $post->published_at?->toIso8601String() }}">{{ $post->published_at?->toFormattedDateString() }}</time>
            @if ($post->excerpt)<p>{{ $post->excerpt }}</p>@endif
        </article>
    @empty
        <p>No posts yet.</p>
    @endforelse

    {{ $posts->links() }}
</section>
@endsection
