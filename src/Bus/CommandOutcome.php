<?php

declare(strict_types=1);

namespace ByteMinus\AiBlog\Bus;

use ByteMinus\AiBlog\Enums\CommandStatus;

/**
 * What a handler did. `result` is stored on the command and returned on
 * every replay; `once` is returned to this caller only and never stored (a
 * confirmation token, for instance). The subject and hashes go to the audit.
 */
final readonly class CommandOutcome
{
    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $once
     */
    public function __construct(
        public CommandStatus $status,
        public array $result,
        public ?string $subjectType = null,
        public ?string $subjectId = null,
        public ?string $oldHash = null,
        public ?string $newHash = null,
        public array $once = [],
    ) {}

    /**
     * @param  array<string, mixed>  $result
     */
    public static function completed(array $result, ?string $subjectType = null, ?string $subjectId = null, ?string $oldHash = null, ?string $newHash = null): self
    {
        return new self(CommandStatus::Completed, $result, $subjectType, $subjectId, $oldHash, $newHash);
    }

    /**
     * Accepted and handed to the queue. The result names the automation jobs
     * that carry the work on.
     *
     * @param  array<string, mixed>  $result
     */
    public static function queued(array $result, ?string $subjectType = null, ?string $subjectId = null): self
    {
        return new self(CommandStatus::Queued, $result, $subjectType, $subjectId);
    }
}
