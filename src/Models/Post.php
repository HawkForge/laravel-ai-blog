<?php

namespace AiBlog\Models;

use AiBlog\Concerns\HasBlogSeo;
use AiBlog\Enums\PostStatus;
use AiBlog\Events\PostPublished;
use AiBlog\Events\PostScheduled;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $title
 * @property string $slug
 * @property string|null $excerpt
 * @property string|null $body
 * @property PostStatus $status
 * @property Carbon|null $published_at
 * @property Carbon|null $scheduled_for
 * @property Carbon|null $last_reviewed_at
 * @property string|null $seo_title
 * @property string|null $seo_description
 * @property string|null $target_keyword
 * @property string|null $canonical_url
 * @property string|null $featured_image
 * @property string|null $featured_image_alt
 * @property int|null $seo_score
 * @property string|null $ai_assistance
 * @property int|null $category_id
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Post extends Model
{
    use HasBlogSeo;
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'status' => PostStatus::class,
        'published_at' => 'datetime',
        'scheduled_for' => 'datetime',
        'last_reviewed_at' => 'datetime',
        'meta' => 'array',
    ];

    public function getTable(): string
    {
        return config('ai-blog.table_prefix', 'ai_blog_').'posts';
    }

    /**
     * Defaults are applied here rather than in a `creating` listener so they still
     * run when model events are muted (Event::fake(), withoutEvents(), saveQuietly()).
     */
    protected function performInsert(Builder $query): bool
    {
        $this->slug ??= Str::slug($this->title);
        $this->status ??= PostStatus::Draft;

        return parent::performInsert($query);
    }

    // ---------------------------------------------------------------- relations

    public function category(): BelongsTo
    {
        return $this->belongsTo(config('ai-blog.models.category'));
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(
            config('ai-blog.models.tag'),
            config('ai-blog.table_prefix', 'ai_blog_').'post_tag',
            'post_id',
            'tag_id'
        );
    }

    public function author(): MorphTo
    {
        return $this->morphTo();
    }

    // ------------------------------------------------------------------ scopes

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('status', PostStatus::Published)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', PostStatus::Draft);
    }

    public function scopeDueForPublishing(Builder $query): Builder
    {
        return $query
            ->where('status', PostStatus::Scheduled)
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', now());
    }

    /**
     * @param  Builder<Post>  $query
     * @return Builder<Post>
     */
    public function scopeStale(Builder $query, ?int $days = null): Builder
    {
        $days ??= (int) config('ai-blog.seo.stale_after_days', 365);
        $cutoff = now()->subDays($days);

        return $query->published()->where(function (Builder $q) use ($cutoff) {
            $q->where(function (Builder $q2) use ($cutoff) {
                $q2->whereNull('last_reviewed_at')->where('published_at', '<', $cutoff);
            })->orWhere('last_reviewed_at', '<', $cutoff);
        });
    }

    // ----------------------------------------------------------------- actions

    public function publish(): static
    {
        $this->forceFill([
            'status' => PostStatus::Published,
            'published_at' => $this->published_at ?? now(),
            'scheduled_for' => null,
        ])->save();

        event(new PostPublished($this));

        return $this;
    }

    public function schedule(\DateTimeInterface $at): static
    {
        $this->forceFill([
            'status' => PostStatus::Scheduled,
            'scheduled_for' => $at,
        ])->save();

        event(new PostScheduled($this));

        return $this;
    }

    public function markReviewed(): static
    {
        $this->forceFill(['last_reviewed_at' => now()])->save();

        return $this;
    }

    public function isPublished(): bool
    {
        return $this->status === PostStatus::Published
            && $this->published_at !== null
            && $this->published_at->isPast();
    }

    // ------------------------------------------------------------ HasBlogSeo

    public function getSeoTitleField(): string
    {
        return 'seo_title';
    }

    public function getSeoDescriptionField(): string
    {
        return 'seo_description';
    }

    public function getBodyField(): string
    {
        return 'body';
    }

    public function getTargetKeywordField(): string
    {
        return 'target_keyword';
    }
}
