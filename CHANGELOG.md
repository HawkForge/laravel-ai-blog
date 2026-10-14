# Changelog

## 0.1.0 - 2026-10-14

- Headless core: Post, Category, Tag; draft/scheduled/published lifecycle; `HasBlogSeo` trait for existing models
- SEO: on-page score with fixes, TF-IDF internal-link suggestions, stale-post detection, BlogPosting + FAQPage JSON-LD
- AI (laravel/ai): brief → draft, enrich SEO fields, rewrite, refresh suggestions, translate; `FakeAssistant` for tests and no-AI mode
- MCP (laravel/mcp): BlogServer with list/get/create/update/draft-from-keyword/score/suggest-links/schedule tools and a style-guide resource
- Commands: `ai-blog:publish-scheduled`, `ai-blog:audit`
- Opt-in public routes, RSS feed, Blade components
- Safe fetching: `SafeFetcher` with URL and IP policy, DNS pinning, robots.txt, size and time caps; RSS/Atom/RDF/JSON Feed parser; text extraction that marks fetched content as untrusted
- Command bus: abilities, idempotency keys, dry runs and an audit trail (`commands` and `audit_events` tables)
- Requires PHP 8.2 to 8.5 and Laravel 10 to 13; tested at the newest and the oldest dependency versions
- Text extraction uses PHP 8.4's HTML5 parser where available and libxml2's HTML parser on older PHP
