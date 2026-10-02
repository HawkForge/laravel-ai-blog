<?php

declare(strict_types=1);

namespace AiBlog\Bus;

use AiBlog\Models\AuditEvent;
use AiBlog\Models\Command;
use Illuminate\Support\Facades\Request;

/**
 * One audit event per command: what ran, what was refused and what failed.
 * The action is the command name; the outcome, the command id and the
 * credential (never its token) go in meta.
 */
final class CommandAudit
{
    public function accepted(Actor $actor, Command $command, CommandOutcome $outcome): void
    {
        $this->write($actor, $command->name, [
            'subject_type' => $outcome->subjectType,
            'subject_id' => $outcome->subjectId,
            'old_hash' => $outcome->oldHash,
            'new_hash' => $outcome->newHash,
        ], [
            'command_id' => $command->getKey(),
            'outcome' => $command->dry_run ? 'dry_run' : $outcome->status->value,
        ]);
    }

    /**
     * A refusal, before or after the command was stored.
     */
    public function refused(Actor $actor, CommandRequest $request, string $reason, ?Command $command = null): void
    {
        $this->write($actor, mb_substr($request->name, 0, 64), ['reason' => mb_substr($reason, 0, 1000)], [
            'command_id' => $command?->getKey(),
            'outcome' => 'refused',
            'dry_run' => $request->dryRun,
        ]);
    }

    public function failed(Actor $actor, Command $command, string $exception): void
    {
        $this->write($actor, $command->name, ['reason' => 'The command failed on the server.'], [
            'command_id' => $command->getKey(),
            'outcome' => 'failed',
            'exception' => $exception,
        ]);
    }

    /**
     * @param  array<string, mixed>  $columns
     * @param  array<string, mixed>  $meta
     */
    private function write(Actor $actor, string $action, array $columns, array $meta): void
    {
        AuditEvent::query()->create([
            'actor_type' => $actor->type,
            'actor_id' => $actor->id,
            'action' => $action,
            'meta' => array_filter($actor->meta + $meta, fn (mixed $value): bool => $value !== null),
            'ip' => Request::ip(),
            ...$columns,
        ]);
    }
}
