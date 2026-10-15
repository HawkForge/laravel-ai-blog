<?php

namespace ByteMinus\AiBlog\Tests\Feature\Bus\Handlers;

use ByteMinus\AiBlog\Bus\CommandContext;
use ByteMinus\AiBlog\Bus\CommandHandler;
use ByteMinus\AiBlog\Bus\CommandOutcome;
use ByteMinus\AiBlog\Enums\CommandStatus;

final class IssuesConfirmation extends CommandHandler
{
    public function ability(): ?string
    {
        return 'posts.publish';
    }

    public function handle(CommandContext $context): CommandOutcome
    {
        return CommandOutcome::completed(['published' => true]);
    }

    public function preview(CommandContext $context): CommandOutcome
    {
        return new CommandOutcome(CommandStatus::Completed, ['would_publish' => 1], once: ['confirmation_token' => 'tok-secret-123']);
    }
}
