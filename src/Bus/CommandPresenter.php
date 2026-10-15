<?php

declare(strict_types=1);

namespace ByteMinus\AiBlog\Bus;

use ByteMinus\AiBlog\Models\Command;

/**
 * How a stored command looks to whoever asked for it. Keys of the result
 * starting with an underscore are the command layer's own bookkeeping (a
 * confirmation's hash) and are never shown.
 */
final class CommandPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function publicResult(Command $command): array
    {
        return array_filter(
            (array) $command->result,
            fn (string|int $key): bool => ! str_starts_with((string) $key, '_'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @return array{id: string, command: string, dry_run: bool, status: string, result: array<string, mixed>, error: string|null, created_at: string|null, completed_at: string|null}
     */
    public static function present(Command $command): array
    {
        return [
            'id' => (string) $command->getKey(),
            'command' => $command->name,
            'dry_run' => (bool) $command->dry_run,
            'status' => $command->status->value,
            'result' => self::publicResult($command),
            'error' => $command->error,
            'created_at' => $command->created_at?->toIso8601String(),
            'completed_at' => $command->completed_at?->toIso8601String(),
        ];
    }
}
