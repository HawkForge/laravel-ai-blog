<?php

namespace AiBlog;

use AiBlog\Ai\Contracts\Assistant;
use AiBlog\Ai\FakeAssistant;
use AiBlog\Commands\AuditCommand;
use AiBlog\Commands\PublishScheduledCommand;
use AiBlog\Seo\LinkSuggester;
use AiBlog\Seo\SeoAnalyzer;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class AiBlogServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-ai-blog')
            ->hasConfigFile('ai-blog')
            ->hasViews('ai-blog')
            ->hasMigration('create_ai_blog_tables')
            ->hasCommands([
                PublishScheduledCommand::class,
                AuditCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(SeoAnalyzer::class);
        $this->app->singleton(LinkSuggester::class);

        $this->app->singleton(Assistant::class, function ($app) {
            if (! config('ai-blog.ai.enabled', true)) {
                return new FakeAssistant;
            }

            return $app->make(config('ai-blog.ai.assistant', FakeAssistant::class));
        });

        $this->app->singleton(AiBlog::class);
    }

    public function packageBooted(): void
    {
        if (config('ai-blog.routes.enabled')) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }

        if (config('ai-blog.mcp.enabled') && class_exists(\Laravel\Mcp\Facades\Mcp::class)) {
            \Laravel\Mcp\Facades\Mcp::web(
                config('ai-blog.mcp.path', '/mcp/ai-blog'),
                \AiBlog\Mcp\BlogServer::class
            )->middleware(config('ai-blog.mcp.middleware', ['auth:sanctum']));
        }
    }
}
