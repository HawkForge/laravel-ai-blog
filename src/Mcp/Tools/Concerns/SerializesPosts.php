<?php

namespace AiBlog\Mcp\Tools\Concerns;

use Illuminate\Database\Eloquent\Model;

trait SerializesPosts
{
    protected function postModel(): string
    {
        return config('ai-blog.models.post');
    }

    protected function findPost(int|string $id): Model
    {
        return $this->postModel()::query()->findOrFail($id);
    }

    protected function summary(Model $post): array
    {
        return [
            'id' => $post->getKey(),
            'title' => $post->title,
            'slug' => $post->slug,
            'status' => $post->status?->value ?? $post->status,
            'target_keyword' => $post->target_keyword,
            'seo_score' => $post->seo_score,
            'published_at' => $post->published_at?->toIso8601String(),
            'scheduled_for' => $post->scheduled_for?->toIso8601String(),
            'updated_at' => $post->updated_at?->toIso8601String(),
        ];
    }

    protected function full(Model $post): array
    {
        return $this->summary($post) + [
            'excerpt' => $post->excerpt,
            'seo_title' => $post->seo_title,
            'seo_description' => $post->seo_description,
            'body' => $post->body,
        ];
    }

    protected function json(mixed $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
