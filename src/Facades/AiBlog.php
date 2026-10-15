<?php

namespace ByteMinus\AiBlog\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \ByteMinus\AiBlog\Ai\Contracts\Assistant assistant()
 * @method static \Illuminate\Database\Eloquent\Builder posts()
 * @method static \ByteMinus\AiBlog\Seo\SeoReport score(\Illuminate\Database\Eloquent\Model $post)
 * @method static \Illuminate\Support\Collection suggestLinks(\Illuminate\Database\Eloquent\Model $post, ?int $max = null)
 * @method static \ByteMinus\AiBlog\Ai\Brief brief(string $keyword)
 * @method static \Illuminate\Database\Eloquent\Model draftFromKeyword(string $keyword, array $attributes = [])
 * @method static \Illuminate\Database\Eloquent\Model storeDraft(\ByteMinus\AiBlog\Ai\Draft $draft, array $attributes = [])
 * @method static array enrichSeo(\Illuminate\Database\Eloquent\Model $post)
 * @method static \Illuminate\Support\Collection stale(?int $days = null)
 *
 * @see \ByteMinus\AiBlog\AiBlog
 */
class AiBlog extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \ByteMinus\AiBlog\AiBlog::class;
    }
}
