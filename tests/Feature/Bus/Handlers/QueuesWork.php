<?php

namespace AiBlog\Tests\Feature\Bus\Handlers;

use AiBlog\Bus\CommandContext;
use AiBlog\Bus\CommandHandler;
use AiBlog\Bus\CommandOutcome;

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
