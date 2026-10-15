<?php

use ByteMinus\AiBlog\Ai\LaravelAiAssistant;
use ByteMinus\AiBlog\Ai\Prompts;
use Laravel\Ai\AnonymousAgent;

// laravel/ai is an optional dependency (PHP 8.3+, Laravel 12+), so this only runs where it is installed.
beforeEach(function () {
    if (! class_exists(AnonymousAgent::class)) {
        $this->markTestSkipped('laravel/ai is not installed.');
    }
});

it('sends the prompt through laravel/ai and returns the text', function () {
    AnonymousAgent::fake(['"Laravel Queues"']);

    $keyword = app(LaravelAiAssistant::class)->extractKeyword('Running queue workers', 'How to run Laravel queue workers in production.');

    expect($keyword)->toBe('laravel queues');
    AnonymousAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Running queue workers')
        && $prompt->agent->instructions === Prompts::system());
});

it('passes the configured provider and model', function () {
    config(['ai-blog.ai.provider' => 'anthropic', 'ai-blog.ai.model' => 'claude-sonnet-5-5']);
    AnonymousAgent::fake(['ok']);

    app(LaravelAiAssistant::class)->extractKeyword('T', 'B');

    AnonymousAgent::assertPrompted(fn ($prompt) => $prompt->model === 'claude-sonnet-5-5');
});
