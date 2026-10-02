<?php

namespace AiBlog\Models;

use AiBlog\Enums\AuditActor;
use AiBlog\Enums\CommandStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A request to change something, from a person, ChatGPT, a job or the Brain.
 * The idempotency key is unique, so a retried request finds the command it
 * already made.
 *
 * @property string $id
 * @property string $name
 * @property AuditActor $actor_type
 * @property string|null $actor_id
 * @property array<string, mixed>|null $params
 * @property string $idempotency_key
 * @property bool $dry_run
 * @property CommandStatus $status
 * @property array<string, mixed>|null $result
 * @property string|null $error
 */
class Command extends Model
{
    use HasUlids;

    protected $fillable = ['name', 'actor_type', 'actor_id', 'params', 'idempotency_key', 'dry_run', 'status', 'result', 'error', 'started_at', 'completed_at'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'dry_run' => false,
        'status' => 'queued',
    ];

    public function getTable(): string
    {
        return config('ai-blog.table_prefix', 'ai_blog_').'commands';
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'actor_type' => AuditActor::class,
            'params' => 'array',
            'result' => 'array',
            'dry_run' => 'boolean',
            'status' => CommandStatus::class,
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
