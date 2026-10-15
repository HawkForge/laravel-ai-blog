<?php

namespace ByteMinus\AiBlog\Tests;

use ByteMinus\AiBlog\AiBlogServiceProvider;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        // laravel/ai is optional; when it is installed, boot it so the integration test can use its fakes.
        return array_merge(
            class_exists(AiServiceProvider::class) ? [AiServiceProvider::class] : [],
            [AiBlogServiceProvider::class],
        );
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('ai-blog.ai.enabled', false);
    }

    protected function defineDatabaseMigrations(): void
    {
        foreach (['create_ai_blog_tables', 'create_ai_blog_command_tables'] as $stub) {
            $migration = include __DIR__."/../database/migrations/{$stub}.php.stub";
            $migration->up();
        }
    }
}
