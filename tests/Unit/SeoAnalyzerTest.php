<?php

use AiBlog\Models\Post;
use AiBlog\Seo\SeoAnalyzer;
use AiBlog\Seo\Text;

it('gives a high score to a well-optimized post', function () {
    $post = Post::create([
        'title' => 'Laravel Queues: A Practical Guide',
        'slug' => 'laravel-queues-practical-guide',
        'body' => goodBody('laravel queues'),
        'seo_description' => 'Learn how Laravel queues work, how to set them up, and the common mistakes to avoid so your app stays fast under load for every user.',
        'target_keyword' => 'laravel queues',
    ]);

    $report = app(SeoAnalyzer::class)->analyze($post);

    expect($report->score)->toBeGreaterThanOrEqual(90)
        ->and($report->grade())->toBe('A')
        ->and($report->fixes())->toBeEmpty();
});

it('lists plain-English fixes for a weak post', function () {
    $post = Post::create([
        'title' => str_repeat('A very long title that goes on and on ', 3),
        'body' => 'Too short. No headings, no links.',
        'target_keyword' => 'laravel queues',
    ]);

    $report = app(SeoAnalyzer::class)->analyze($post);

    expect($report->score)->toBeLessThan(40)
        ->and($report->fixes())->toContain('Expand the post to at least 600 words (currently 6).')
        ->and(collect($report->fixes())->first(fn ($f) => str_contains($f, 'Shorten the title')))->not->toBeNull()
        ->and(collect($report->fixes())->first(fn ($f) => str_contains($f, 'Include "laravel queues" in the title')))->not->toBeNull()
        ->and(collect($report->fixes())->first(fn ($f) => str_contains($f, 'meta description')))->not->toBeNull();
});

it('asks for a target keyword when none is set', function () {
    $post = Post::create(['title' => 'Untargeted', 'body' => goodBody()]);

    $report = app(SeoAnalyzer::class)->analyze($post);

    expect($report->fixes())->toContain('Set a target keyword so the analyzer can check where it appears.');
});

it('counts words, headings, links and images in markdown', function () {
    $body = goodBody('laravel queues');

    expect(Text::wordCount($body))->toBeGreaterThan(600)
        ->and(Text::headings($body, 2))->toHaveCount(4)
        ->and(Text::linkCount($body))->toBe(2)
        ->and(Text::imagesMissingAlt($body))->toBeEmpty()
        ->and(Text::imagesMissingAlt('![](x.png) <img src="y.png">'))->toHaveCount(2);
});

it('exposes the report through the trait and facade', function () {
    $post = Post::create(['title' => 'T', 'body' => goodBody(), 'target_keyword' => 'laravel queues']);

    expect($post->seoReport()->score)->toBe(\AiBlog\Facades\AiBlog::score($post)->score)
        ->and($post->seoReport()->toArray())->toHaveKeys(['score', 'grade', 'checks', 'fixes']);
});
