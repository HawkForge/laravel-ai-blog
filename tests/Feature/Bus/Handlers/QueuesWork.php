<?php

namespace ByteMinus\AiBlog\Tests\Feature\Bus\Handlers;

use ByteMinus\AiBlog\Bus\CommandContext;
use ByteMinus\AiBlog\Bus\CommandHandler;
use ByteMinus\AiBlog\Bus\CommandOutcome;

final class QueuesWork extends CommandHandler
{
    public function ability(): ?string
    {
        return null;
    }

    public function handle(CommandContext $context): CommandOutcome
    {
        return CommandOutcome::queued(['job' => 'refresh'], 'post', '7');
    }
}
