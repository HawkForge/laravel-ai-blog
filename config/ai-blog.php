<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    | Swap in your own models. Any class here must extend (or behave like)
    | the package model it replaces. If you already have a Post model, add
    | the AiBlog\Concerns\HasBlogSeo trait to it and point `post` at it.
    */
    'models' => [
        'post' => \AiBlog\Models\Post::class,
        'category' => \AiBlog\Models\Category::class,
        'tag' => \AiBlog\Models\Tag::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Table prefix
    |--------------------------------------------------------------------------
    */
    'table_prefix' => 'ai_blog_',

    /*
    |--------------------------------------------------------------------------
    | Public routes (opt-in)
    |--------------------------------------------------------------------------
    | Headless by default. Flip `enabled` to get /blog, /blog/{slug},
    | /blog/category/{slug}, /blog/tag/{slug} and /blog/feed.
    */
    'routes' => [
        'enabled' => false,
        'prefix' => 'blog',
        'middleware' => ['web'],
        'layout' => 'layouts.app',
    ],

    /*
    |--------------------------------------------------------------------------
    | SEO
    |--------------------------------------------------------------------------
    */
    'seo' => [
        'site_name' => env('APP_NAME', 'Blog'),
        'title_max' => 60,
        'description_min' => 120,
        'description_max' => 160,
        'min_words' => 600,
        'stale_after_days' => 365,
        'internal_links' => [
            'max_suggestions' => 5,
            'min_score' => 0.15,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI
    |--------------------------------------------------------------------------
    | Built on the official laravel/ai SDK. `assistant` can be any class
    | implementing AiBlog\Ai\Contracts\Assistant. Auto-publishing is OFF by
    | default and must be enabled knowingly — Laravel AI Blog assists writers, it
    | does not replace them.
    */
    'ai' => [
        'enabled' => env('AI_BLOG_AI_ENABLED', true),
        'assistant' => \AiBlog\Ai\LaravelAiAssistant::class,
        'provider' => env('AI_BLOG_AI_PROVIDER'),
        'model' => env('AI_BLOG_AI_MODEL'),
        'auto_publish' => false,
        'style_guide' => 'Write clearly and concretely. Short paragraphs. No filler.',
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP server
    |--------------------------------------------------------------------------
    | Requires laravel/mcp. When `enabled`, Laravel AI Blog registers an MCP server
    | at `path` so Claude, ChatGPT, Claude Code or any agent can draft, edit,
    | score and schedule posts. Protect it with your auth middleware.
    */
    'mcp' => [
        'enabled' => env('AI_BLOG_MCP_ENABLED', false),
        'path' => '/mcp/ai-blog',
        'middleware' => ['auth:sanctum'],
    ],

];
