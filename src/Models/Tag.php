<?php

namespace AiBlog\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Tag extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return config('ai-blog.table_prefix', 'ai_blog_').'tags';
    }

    /** Runs even when model events are muted; see Post::performInsert(). */
    protected function performInsert(Builder $query): bool
    {
        $this->slug ??= Str::slug($this->name);

        return parent::performInsert($query);
    }

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(
            config('ai-blog.models.post'),
            config('ai-blog.table_prefix', 'ai_blog_').'post_tag',
            'tag_id',
            'post_id'
        );
    }
}
