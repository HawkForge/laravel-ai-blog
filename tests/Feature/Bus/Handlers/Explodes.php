<?php

namespace AiBlog\Tests\Feature\Bus\Handlers;

use AiBlog\Bus\CommandContext;
use AiBlog\Bus\CommandHandler;
use AiBlog\Bus\CommandOutcome;
use RuntimeException;

final class Explodes extends CommandHandler
{
    public function ability(): ?string
    {
        return null;
    }

    public function handle(CommandContext $context): CommandOutcome
    {
        throw new RuntimeException('Secret internal detail');
    }
}
