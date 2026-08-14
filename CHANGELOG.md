# Changelog

## Unreleased

- Headless core: Post, Category, Tag; draft/scheduled/published lifecycle; `HasBlogSeo` trait for existing models
- SEO: on-page score with fixes, TF-IDF internal-link suggestions, stale-post detection, BlogPosting + FAQPage JSON-LD
- AI (laravel/ai): brief → draft, enrich SEO fields, rewrite, refresh suggestions, translate; `FakeAssistant` for tests and no-AI mode
- MCP (laravel/mcp): BlogServer with list/get/create/update/draft-from-keyword/score/suggest-links/schedule tools and a style-guide resource
- Commands: `ai-blog:publish-scheduled`, `ai-blog:audit`
- Opt-in public routes, RSS feed, Blade components
