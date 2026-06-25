<?php

namespace AiBlog\Concerns;

use AiBlog\Ai\Contracts\Assistant;
use AiBlog\Seo\LinkSuggester;
use AiBlog\Seo\SeoAnalyzer;
use AiBlog\Seo\SeoReport;
use AiBlog\Seo\StructuredData;
use Illuminate\Support\Collection;

/**
 * Add to ANY Eloquent model that has a title, a body and (optionally) SEO fields.
 * The package's own Post uses it; so can your existing App\Models\Post.
 *
 * Override the get*Field() methods if your columns are named differently.
 */
trait HasBlogSeo
{
    public function getTitleField(): string
    {
        return 'title';
    }

    public function getBodyField(): string
    {
        return 'body';
    }

    public function getSlugField(): string
    {
        return 'slug';
    }

    public function getSeoTitleField(): string
    {
        return 'seo_title';
    }

    public function getSeoDescriptionField(): string
    {
        return 'seo_description';
    }

    public function getTargetKeywordField(): string
    {
        return 'target_keyword';
    }

    /** Run the on-page SEO analysis. Returns a score (0–100) plus plain-English fixes. */
    public function seoReport(): SeoReport
    {
        return app(SeoAnalyzer::class)->analyze($this);
    }

    /** Suggest internal links to other posts, with anchor text. */
    public function suggestedLinks(?int $max = null): Collection
    {
        return app(LinkSuggester::class)->suggestFor($this, $max);
    }

    /** JSON-LD BlogPosting for this post. */
    public function structuredData(): array
    {
        return StructuredData::blogPosting($this);
    }

    /** The AI assistant, scoped to this post. */
    public function ai(): Assistant
    {
        return app(Assistant::class);
    }

    public function seoTitle(): string
    {
        return (string) ($this->{$this->getSeoTitleField()} ?: $this->{$this->getTitleField()});
    }

    public function seoDescription(): string
    {
        return (string) ($this->{$this->getSeoDescriptionField()} ?? '');
    }

    public function bodyText(): string
    {
        return (string) ($this->{$this->getBodyField()} ?? '');
    }

    public function targetKeyword(): ?string
    {
        $kw = $this->{$this->getTargetKeywordField()} ?? null;

        return $kw ? trim((string) $kw) : null;
    }
}
