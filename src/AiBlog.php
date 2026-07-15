<?php

namespace AiBlog;

use AiBlog\Ai\Brief;
use AiBlog\Ai\Contracts\Assistant;
use AiBlog\Ai\Draft;
use AiBlog\Seo\LinkSuggester;
use AiBlog\Seo\SeoAnalyzer;
use AiBlog\Seo\SeoReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The single entry point: AiBlog::draftFromKeyword('laravel queues'), AiBlog::score($post), ...
 * Everything here is also reachable through the HasBlogSeo trait and the MCP tools.
 */
class AiBlog
{
    public function __construct(
        protected Assistant $assistant,
        protected SeoAnalyzer $analyzer,
        protected LinkSuggester $links,
    ) {}

    public function assistant(): Assistant
    {
        return $this->assistant;
    }

    /** @return class-string<Model> */
    public function postModel(): string
    {
        return config('ai-blog.models.post');
    }

    public function posts(): Builder
    {
        return $this->postModel()::query();
    }

    public function score(Model $post): SeoReport
    {
        return $this->analyzer->analyze($post);
    }

    public function suggestLinks(Model $post, ?int $max = null): Collection
    {
        return $this->links->suggestFor($post, $max);
    }

    /** Research a keyword, giving the AI the site's existing titles so it links instead of duplicates. */
    public function brief(string $keyword): Brief
    {
        $existing = $this->posts()->published()->limit(50)->pluck('title')->all();

        return $this->assistant->brief($keyword, ['existing_titles' => $existing]);
    }

    /** Brief → draft → saved as a DRAFT post. Never publishes. */
    public function draftFromKeyword(string $keyword, array $attributes = []): Model
    {
        $brief = $this->brief($keyword);
        $draft = $this->assistant->draft($brief);

        return $this->storeDraft($draft, $attributes + ['meta' => ['brief' => $brief->toArray()]]);
    }

    public function storeDraft(Draft $draft, array $attributes = []): Model
    {
        $attrs = array_replace_recursive($draft->toPostAttributes(), $attributes);
        $attrs['status'] = 'draft'; // non-negotiable

        $post = $this->postModel()::create($attrs);

        // Persist the score so lists and admin panels can show it without re-analyzing.
        $post->forceFill(['seo_score' => $this->score($post)->score])->save();

        return $post;
    }

    /** Fill in missing SEO fields on an existing post using the assistant. Returns what changed. */
    public function enrichSeo(Model $post): array
    {
        $changes = [];
        $title = (string) $post->{$post->getTitleField()};

        if (! $post->targetKeyword()) {
            $changes[$post->getTargetKeywordField()] = $this->assistant->extractKeyword($title, $post->bodyText());
        }
        $keyword = $changes[$post->getTargetKeywordField()] ?? $post->targetKeyword();

        if ($post->seoDescription() === '') {
            $changes[$post->getSeoDescriptionField()] = $this->assistant->writeMetaDescription($title, $post->bodyText(), $keyword);
        }

        if ($changes !== []) {
            $post->forceFill($changes + ['ai_assistance' => $post->ai_assistance ?? 'assisted'])->save();
            $post->forceFill(['seo_score' => $this->score($post)->score])->save();
        }

        return $changes;
    }

    public function stale(?int $days = null): Collection
    {
        return $this->posts()->stale($days)->get();
    }
}
