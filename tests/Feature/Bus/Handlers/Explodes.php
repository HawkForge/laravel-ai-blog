<?php

namespace ByteMinus\AiBlog\Tests\Feature\Bus\Handlers;

use ByteMinus\AiBlog\Bus\CommandContext;
use ByteMinus\AiBlog\Bus\CommandHandler;
use ByteMinus\AiBlog\Bus\CommandOutcome;
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
