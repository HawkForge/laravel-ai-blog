<?php

namespace AiBlog\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Category extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return config('ai-blog.table_prefix', 'ai_blog_').'categories';
    }

    /** Runs even when model events are muted; see Post::performInsert(). */
    protected function performInsert(Builder $query): bool
    {
        $this->slug ??= Str::slug($this->name);

        return parent::performInsert($query);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(config('ai-blog.models.post'), 'category_id');
    }
}
