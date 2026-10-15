<?php

use ByteMinus\AiBlog\Models\Post;
use ByteMinus\AiBlog\Seo\LinkSuggester;

beforeEach(function () {
    $this->queues = Post::create([
        'title' => 'Laravel Queues Explained',
        'body' => goodBody('laravel queues'),
        'status' => 'published',
        'published_at' => now()->subDay(),
    ]);
    $this->horizon = Post::create([
        'title' => 'Monitoring Laravel Queues with Horizon',
        'body' => goodBody('laravel queues horizon dashboard workers'),
        'status' => 'published',
        'published_at' => now()->subDay(),
    ]);
    $this->cooking = Post::create([
        'title' => 'Sourdough Starter Basics',
        'body' => str_repeat('Flour water salt time. Feed the starter daily and watch for bubbles and a pleasant sour smell. ', 60),
        'status' => 'published',
        'published_at' => now()->subDay(),
    ]);
    $this->draft = Post::create([
        'title' => 'Unpublished Laravel Queues Draft',
        'body' => goodBody('laravel queues'),
    ]);
});

it('suggests the related post and not the unrelated one', function () {
    $links = app(LinkSuggester::class)->suggestFor($this->queues);

    $titles = $links->pluck('post.title')->all();

    expect($titles)->toContain('Monitoring Laravel Queues with Horizon')
        ->and($titles)->not->toContain('Sourdough Starter Basics');
});

it('only suggests published posts and never the post itself', function () {
    $links = app(LinkSuggester::class)->suggestFor($this->queues);

    expect($links->pluck('post.id')->all())
        ->not->toContain($this->draft->id)
        ->not->toContain($this->queues->id);
});

it('provides anchor text and a relevance score', function () {
    $first = app(LinkSuggester::class)->suggestFor($this->queues)->first();

    expect($first)->toHaveKeys(['post', 'score', 'anchor'])
        ->and($first['score'])->toBeGreaterThan(0)
        ->and($first['anchor'])->toBeString()->not->toBeEmpty();
});

it('respects the max parameter and is reachable from the trait', function () {
    expect($this->queues->suggestedLinks(1))->toHaveCount(1);
});
