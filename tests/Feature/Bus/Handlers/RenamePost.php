<?php

namespace AiBlog\Tests\Feature\Bus\Handlers;

use AiBlog\Bus\CommandContext;
use AiBlog\Bus\CommandHandler;
use AiBlog\Bus\CommandOutcome;

final class RenamePost extends CommandHandler
{
    public function ability(): ?string
    {
        return 'posts.write';
    }

    public function rules(): array
    {
        return ['title' => ['required', 'string', 'max:120']];
    }

    public function handle(CommandContext $context): CommandOutcome
    {
        HandlerRuns::$count++;

        return CommandOutcome::completed(
            ['title' => $context->params['title'], '_internal' => 'hidden'],
            'post',
            '42',
            hash('sha256', 'old'),
            hash('sha256', $context->params['title']),
        );
    }
}
