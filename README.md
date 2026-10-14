# Laravel AI Blog — the AI-native blog for Laravel

[![tests](https://github.com/HawkForge/laravel-ai-blog/actions/workflows/tests.yml/badge.svg)](https://github.com/HawkForge/laravel-ai-blog/actions)
[![Latest Version](https://img.shields.io/packagist/v/hawkforge/laravel-ai-blog.svg)](https://packagist.org/packages/hawkforge/laravel-ai-blog)
[![License](https://img.shields.io/packagist/l/hawkforge/laravel-ai-blog.svg)](LICENSE.md)

**Install it, and your blog thinks.** Laravel AI Blog gives any Laravel 12/13 app a blog that plans topics,
drafts *with* you, scores itself for search, links itself, keeps itself fresh — and lets Claude,
ChatGPT, Claude Code or any MCP agent write to it.

- **Headless by default.** Models, migrations, a trait for your *existing* Post model. No admin panel forced on you. Flip one flag for `/blog` routes, Blade components, RSS and JSON-LD.
- **SEO that nobody else ships.** An on-page score with plain-English fixes, internal-link suggestions with anchor text, stale-post detection, BlogPosting + FAQPage schema.
- **AI as your editor, not your ghostwriter.** Built on the official [laravel/ai](https://github.com/laravel/ai) SDK — any provider. Brief → draft, meta descriptions, rewrites, refresh suggestions, translations. Everything lands as a **draft**. Nothing auto-publishes unless you say so.
- **Agent-ready.** A built-in [laravel/mcp](https://github.com/laravel/mcp) server: *"write a post on X, score it, link it to related posts, schedule for Monday."*
- **Free. MIT.** No Nova license, no Filament lock-in, no SaaS tether.

## Install

```bash
composer require hawkforge/laravel-ai-blog
php artisan vendor:publish --tag=laravel-ai-blog-migrations
php artisan migrate
```

Optional, for AI and agents:

```bash
composer require laravel/ai laravel/mcp
```

## 60-second tour

```php
use AiBlog\Facades\AiBlog;
use AiBlog\Models\Post;

// Score any post. Deterministic, no API key needed.
$report = $post->seoReport();
$report->score;    // 72
$report->grade();  // "B"
$report->fixes();  // ["Include \"laravel queues\" in the title.", "Add at least two links — ..."]

// Internal links, with anchor text, from your own published posts.
$post->suggestedLinks();   // [['post' => Post, 'anchor' => 'queue workers', 'score' => 0.41], ...]

// AI: keyword → brief → full draft. Saved as a DRAFT for a human to review.
$draft = AiBlog::draftFromKeyword('laravel queues');

// AI: fill in a missing keyword + meta description on an existing post.
AiBlog::enrichSeo($post);

// Publishing is explicit.
$post->schedule(now()->addDays(2));   // then: php artisan ai-blog:publish-scheduled
$post->publish();
```

```bash
php artisan ai-blog:audit            # score every post, list fixes and links
php artisan ai-blog:audit --fix      # let the AI fill missing SEO fields first
php artisan ai-blog:audit --stale    # posts due for a refresh
```

## Already have a Post model?

Keep it. Add the trait, point the config at your model, done.

```php
use AiBlog\Concerns\HasBlogSeo;

class Post extends Model
{
    use HasBlogSeo;

    // Override only what differs from the defaults (title, body, slug, seo_title, seo_description, target_keyword)
    public function getBodyField(): string { return 'content'; }
}
```

```php
// config/ai-blog.php
'models' => ['post' => App\Models\Post::class],
```

## Let agents write your blog

```php
// config/ai-blog.php
'mcp' => [
    'enabled' => true,
    'path' => '/mcp/ai-blog',
    'middleware' => ['auth:sanctum'],
],
```

Then connect:

```bash
claude mcp add --transport http my-blog https://example.com/mcp/ai-blog --header "Authorization: Bearer <token>"
```

Tools: `list_posts`, `get_post`, `create_draft`, `update_post`, `draft_from_keyword`, `score_post`,
`suggest_links`, `schedule_post`. There is deliberately **no `publish_post` tool** — agents draft,
score, link and schedule; a human (or the scheduler, after a human scheduled it) publishes.

For Claude.ai and ChatGPT custom connectors, use laravel/mcp's OAuth support (`Mcp::oauthRoutes()` with Passport).

## Safe fetching (research)

`SafeFetcher` reads a public web page, feed or JSON document so the AI has real sources to work from. Run it
from a queued job or an Artisan command: it **refuses to run inside a web request**.

```php
use AiBlog\Fetching\Feeds\FeedParser;
use AiBlog\Fetching\SafeFetcher;
use AiBlog\Fetching\TextExtractor;

$document = app(SafeFetcher::class)->fetch('https://example.com/feed.xml');

$feed = app(FeedParser::class)->parseDocument($document);   // RSS, Atom, RSS 1.0 or JSON Feed
$page = app(TextExtractor::class)->extract($document);      // readable text, title, author, date
$page->untrusted();                                         // wrap it before it goes near a model
```

On the first URL and on every redirect (at most 5) it checks that the URL is plain http(s) with no credentials,
a standard port and no internal host names; resolves the host itself and requires every address to be public;
**pins the connection to the address it checked**, so DNS rebinding can't move the request inside your network;
obeys `robots.txt`; ignores proxies and cookies; and caps the body size and total time. Anything it returns is
untrusted data: hand it to a model only through `TextExtractor` and `UntrustedText`.

## Command bus

Every change can go through one bus, so a dashboard, an Artisan command, a job and an AI agent all get the same
rules: the actor must hold the command's **ability**, every request carries an **idempotency key** (a retry
replays the stored result instead of running twice), and each outcome is written to an **audit trail**.

```php
use AiBlog\Bus\Actor;
use AiBlog\Bus\CommandBus;
use AiBlog\Bus\CommandContext;
use AiBlog\Bus\CommandHandler;
use AiBlog\Bus\CommandOutcome;
use AiBlog\Bus\CommandRequest;

final class RenamePost extends CommandHandler
{
    public function ability(): ?string { return 'posts.write'; }

    public function rules(): array { return ['title' => ['required', 'string', 'max:120']]; }

    public function handle(CommandContext $context): CommandOutcome
    {
        // ...call your service here...
        return CommandOutcome::completed(['title' => $context->params['title']], 'post', '42');
    }
}
```

```php
// config/ai-blog.php
'commands' => ['handlers' => ['post.rename' => RenamePost::class]],

// AppServiceProvider: nobody manages the blog until you say who may
Gate::define('manageAiBlog', fn ($user) => $user->is_admin);

// anywhere
$receipt = app(CommandBus::class)->dispatch(
    Actor::user($request->user()),
    new CommandRequest('post.rename', idempotencyKey: 'rename-42-v1', params: ['title' => 'New title']),
);

$receipt->result();    // what the caller may see
$receipt->replayed;    // true when this was a retry
```

Pass `dryRun: true` to preview a command without changing anything. Publish and run the migrations to create the
`commands` and `audit_events` tables.

## Public routes (opt-in)

```php
'routes' => ['enabled' => true, 'prefix' => 'blog', 'layout' => 'layouts.app'],
```

Gives you `/blog`, `/blog/{slug}`, `/blog/category/{slug}`, `/blog/tag/{slug}`, `/blog/feed`, and the
Blade components `<x-ai-blog::meta-tags>`, `<x-ai-blog::structured-data>`, `<x-ai-blog::post-body>`.

## Scheduler

```php
// routes/console.php
Schedule::command('ai-blog:publish-scheduled')->everyMinute();
```

## Philosophy

AI that writes thirty posts a month from a keyword list is what search engines are built to bury.
Laravel AI Blog makes a **human writer faster** and an **agent safer**: every AI action produces a draft,
marks unverified claims as `[verify: ...]`, and records how much AI was involved (`ai_assistance`).
Auto-publishing exists as a config flag you turn on knowingly, never as a default.

## Roadmap

- [x] Headless core, SEO score, internal links, stale detection, JSON-LD, RSS
- [x] AI brief → draft, enrich, rewrite, refresh, translate (via laravel/ai)
- [x] MCP server for agents
- [x] Safe fetching: SSRF-guarded fetcher, feed parser, text extraction
- [x] Command bus with idempotency and an audit trail
- [ ] Semantic linking with embeddings
- [ ] Topic intelligence: content gaps and clusters from your existing posts
- [ ] Filament adapter · Nova adapter · Livewire editor
- [ ] Multilingual posts with hreflang
- [ ] Scheduled pipelines ("two drafts a week from this keyword list")

See [CONTRIBUTING.md](CONTRIBUTING.md) — there's a `good first issue` for most of these.

## Security

See [SECURITY.md](SECURITY.md) for how to report a vulnerability privately.

## License

MIT.
