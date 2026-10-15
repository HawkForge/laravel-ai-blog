<?php

use ByteMinus\AiBlog\Ai\Contracts\Assistant;
use ByteMinus\AiBlog\Ai\FakeAssistant;
use ByteMinus\AiBlog\Enums\PostStatus;
use ByteMinus\AiBlog\Facades\AiBlog;
use ByteMinus\AiBlog\Models\Post;

it('drafts a post from a keyword and never publishes it', function () {
    $post = AiBlog::draftFromKeyword('laravel queues');

    expect($post)->toBeInstanceOf(Post::class)
        ->and($post->status)->toBe(PostStatus::Draft)
        ->and($post->ai_assistance)->toBe('drafted')
        ->and($post->target_keyword)->toBe('laravel queues')
        ->and($post->meta['brief']['outline'])->not->toBeEmpty()
        ->and($post->seo_score)->toBeInt();
});

it('refuses to publish a draft even when attributes say so', function () {
    $draft = app(Assistant::class)->draft(app(Assistant::class)->brief('x'));
    $post = AiBlog::storeDraft($draft, ['status' => 'published', 'published_at' => now()]);

    expect($post->status)->toBe(PostStatus::Draft)
        ->and(Post::published()->count())->toBe(0);
});

it('enriches missing SEO fields and records assistance', function () {
    $post = Post::create(['title' => 'Laravel Queues Explained', 'body' => goodBody('laravel queues')]);

    $changes = AiBlog::enrichSeo($post);

    expect($changes)->toHaveKeys(['target_keyword', 'seo_description'])
        ->and($post->fresh()->ai_assistance)->toBe('assisted')
        ->and($post->fresh()->seo_description)->not->toBeEmpty();
});

it('does not touch fields that are already set', function () {
    $post = Post::create(['title' => 'T', 'body' => 'x', 'target_keyword' => 'kw', 'seo_description' => 'd']);

    expect(AiBlog::enrichSeo($post))->toBe([])
        ->and($post->fresh()->ai_assistance)->toBeNull();
});

it('uses the fake assistant when ai is disabled', function () {
    // The test environment sets ai-blog.ai.enabled=false; the provider must then bind the fake.
    expect(app(Assistant::class))->toBeInstanceOf(FakeAssistant::class);
});

it('audits posts from the console', function () {
    Post::create(['title' => 'Audit me', 'body' => goodBody(), 'target_keyword' => 'laravel queues']);

    $this->artisan('ai-blog:audit')->assertSuccessful()->expectsOutputToContain('Audit me');
});
