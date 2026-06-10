<?php

namespace AiBlog\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class BlogController extends Controller
{
    protected function posts()
    {
        return config('ai-blog.models.post')::query()->published()->latest('published_at');
    }

    public function index(): View
    {
        return view('ai-blog::index', ['posts' => $this->posts()->paginate(12)]);
    }

    public function show(string $slug): View
    {
        $post = $this->posts()->where('slug', $slug)->firstOrFail();

        return view('ai-blog::show', [
            'post' => $post,
            'related' => $post->suggestedLinks(3)->pluck('post'),
        ]);
    }

    public function category(string $slug): View
    {
        $category = config('ai-blog.models.category')::where('slug', $slug)->firstOrFail();

        return view('ai-blog::index', [
            'posts' => $this->posts()->where('category_id', $category->getKey())->paginate(12),
            'heading' => $category->name,
        ]);
    }

    public function tag(string $slug): View
    {
        $tag = config('ai-blog.models.tag')::where('slug', $slug)->firstOrFail();

        return view('ai-blog::index', [
            'posts' => $this->posts()->whereHas('tags', fn ($q) => $q->where('slug', $slug))->paginate(12),
            'heading' => $tag->name,
        ]);
    }

    public function feed(): Response
    {
        $xml = view('ai-blog::feed', ['posts' => $this->posts()->limit(20)->get()])->render();

        return response($xml, 200, ['Content-Type' => 'application/rss+xml; charset=UTF-8']);
    }
}
