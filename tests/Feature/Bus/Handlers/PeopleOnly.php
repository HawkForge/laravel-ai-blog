<?php

namespace ByteMinus\AiBlog\Tests\Feature\Bus\Handlers;

use ByteMinus\AiBlog\Bus\CommandContext;
use ByteMinus\AiBlog\Bus\CommandHandler;
use ByteMinus\AiBlog\Bus\CommandOutcome;

final class PeopleOnly extends CommandHandler
{
    public function ability(): ?string
    {
        return null;
    }

    public function handle(CommandContext $context): CommandOutcome
    {
        HandlerRuns::$count++;

        return CommandOutcome::completed(['ok' => true]);
    }
}
