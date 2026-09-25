<?php

declare(strict_types=1);

namespace AiBlog\Bus;

use AiBlog\Models\Command;

/**
 * Everything a handler gets: the stored command, who asked and the
 * validated params.
 */
final readonly class CommandContext
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public Command $command,
        public Actor $actor,
        public array $params,
    ) {}
}
