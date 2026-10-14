<?php

use AiBlog\Enums\PostStatus;
use AiBlog\Events\PostPublished;
use AiBlog\Events\PostScheduled;
use AiBlog\Models\Category;
use AiBlog\Models\Post;
use AiBlog\Models\Tag;
use AiBlog\Seo\StructuredData;
use Illuminate\Support\Facades\Event;

it('creates a draft with a slug by default', function () {
    $post = Post::create(['title' => 'Hello World', 'body' => 'x']);

    expect($post->status)->toBe(PostStatus::Draft)
        ->and($post->slug)->toBe('hello-world')
        ->and($post->isPublished())->toBeFalse();
});

it('publishes and fires an event', function () {
    Event::fake();
    $post = Post::create(['title' => 'P', 'body' => 'x'])->publish();

    expect($post->isPublished())->toBeTrue()
        ->and(Post::published()->count())->toBe(1);
    Event::assertDispatched(PostPublished::class);
});

it('schedules, then the command publishes it when due', function () {
    Event::fake();
    $post = Post::create(['title' => 'S', 'body' => 'x'])->schedule(now()->addHour());

    Event::assertDispatched(PostScheduled::class);
    expect($post->status)->toBe(PostStatus::Scheduled)
        ->and(Post::published()->count())->toBe(0);

    $this->artisan('ai-blog:publish-scheduled')->assertSuccessful();
    expect(Post::published()->count())->toBe(0);

    $this->travel(2)->hours();
    $this->artisan('ai-blog:publish-scheduled')->assertSuccessful();
    expect(Post::published()->count())->toBe(1);
});

it('finds stale posts', function () {
    Post::create(['title' => 'Old', 'body' => 'x', 'status' => 'published', 'published_at' => now()->subYears(2)]);
    Post::create(['title' => 'Fresh', 'body' => 'x', 'status' => 'published', 'published_at' => now()->subDay()]);
    Post::create(['title' => 'Old but reviewed', 'body' => 'x', 'status' => 'published',
        'published_at' => now()->subYears(2), 'last_reviewed_at' => now()->subDay()]);

    expect(Post::stale()->pluck('title')->all())->toBe(['Old']);
});

it('attaches categories and tags', function () {
    $cat = Category::create(['name' => 'Laravel Tips']);
    $tag = Tag::create(['name' => 'Queues']);
    $post = Post::create(['title' => 'T', 'body' => 'x', 'category_id' => $cat->id]);
    $post->tags()->attach($tag);

    expect($post->category->slug)->toBe('laravel-tips')
        ->and($post->tags)->toHaveCount(1)
        ->and($tag->posts)->toHaveCount(1);
});

it('renders structured data', function () {
    $post = Post::create(['title' => 'T', 'body' => goodBody(), 'status' => 'published', 'published_at' => now()]);

    expect($post->structuredData())->toMatchArray(['@type' => 'BlogPosting', 'headline' => 'T'])
        ->and(StructuredData::faqFromBody($post->body)['mainEntity'][0]['name'])->toBe('Is laravel queues hard?');
});
