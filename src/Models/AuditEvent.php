<?php

namespace AiBlog\Models;

use AiBlog\Enums\AuditActor;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Who changed what, from where, and what was refused. Append-only. Never holds
 * a token or a post body.
 *
 * @property string $id
 * @property AuditActor $actor_type
 * @property string|null $actor_id
 * @property string $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string|null $old_hash
 * @property string|null $new_hash
 * @property string|null $reason
 * @property array<string, mixed>|null $meta
 * @property string|null $ip
 */
class AuditEvent extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    public function getTable(): string
    {
        return config('ai-blog.table_prefix', 'ai_blog_').'audit_events';
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Audit events are append-only.');
        });

        static::deleting(function (): never {
            throw new LogicException('Audit events are append-only.');
        });
    }

    /** @var array<string, string> */
    protected $casts = ['actor_type' => AuditActor::class, 'meta' => 'array', 'created_at' => 'datetime'];
}
