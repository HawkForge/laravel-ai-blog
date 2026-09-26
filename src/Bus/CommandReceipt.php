<?php

declare(strict_types=1);

namespace AiBlog\Bus;

use AiBlog\Models\Command;

/**
 * The stored command a request made or found, whether it was a replay, and
 * anything returned to this caller only.
 */
final readonly class CommandReceipt
{
    /**
     * @param  array<string, mixed>  $once
     */
    public function __construct(
        public Command $command,
        public bool $replayed,
        public array $once = [],
    ) {}

    /**
     * The stored result as a caller may see it, plus what is shown once.
     *
     * @return array<string, mixed>
     */
    public function result(): array
    {
        return CommandPresenter::publicResult($this->command) + $this->once;
    }
}
