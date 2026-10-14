# Contributing to Laravel AI Blog

Thanks for helping. Laravel AI Blog is MIT and built by the community — small PRs merge fast.

## Setup

```bash
git clone https://github.com/HawkForge/laravel-ai-blog && cd laravel-ai-blog
composer install
composer test
```

## Before you open a PR

- `composer test` passes
- `composer format` (Pint) and `composer analyse` (PHPStan) are clean
- New behaviour has a test; a bug fix has a test that fails without the fix
- One change per PR

## Where things live

| Area | Path |
| --- | --- |
| On-page SEO checks | `src/Seo/SeoAnalyzer.php` — add a `SeoCheck`, give it a weight and a plain-English fix |
| Internal linking | `src/Seo/LinkSuggester.php` |
| Schema / JSON-LD | `src/Seo/StructuredData.php` |
| AI prompts | `src/Ai/Prompts.php` — improve a prompt without touching logic |
| AI provider glue | `src/Ai/LaravelAiAssistant.php::complete()` — the only method that calls the SDK |
| Agent tools | `src/Mcp/Tools/` — one class per tool |
| Safe fetching | `src/Fetching/` — `UrlGuard` and `IpAddressPolicy` decide what may be fetched, `SafeFetcher` does it |
| Command bus | `src/Bus/` — one handler per command, ability + idempotency + audit live in `CommandBus` |
| Opt-in blog views | `resources/views/` |

## Good first issues

Look for the `good first issue` label. Typical ones: a new SEO check (e.g. "title starts with the keyword"),
a schema type (`HowTo`, `Article`), a translation of the fix messages, a Blade component, a prompt improvement
with before/after examples, or a test for an edge case in `Text`.

## Principles (please keep these)

1. Every AI action produces a **draft**. Nothing publishes without a human or the scheduler.
2. The deterministic path (score, links, schema) must work with **no API key**.
3. No admin panel is required. Adapters are optional packages or opt-in classes.
4. Mark anything the AI couldn't verify as `[verify: ...]` rather than guessing.

## Security

Please report vulnerabilities privately, not as a public issue. See [SECURITY.md](SECURITY.md).
