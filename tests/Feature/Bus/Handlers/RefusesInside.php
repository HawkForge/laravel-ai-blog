<?php

namespace ByteMinus\AiBlog\Tests\Feature\Bus\Handlers;

use ByteMinus\AiBlog\Bus\CommandContext;
use ByteMinus\AiBlog\Bus\CommandHandler;
use ByteMinus\AiBlog\Bus\CommandOutcome;
use ByteMinus\AiBlog\Bus\Exceptions\CommandRefused;
use Illuminate\Validation\ValidationException;

final class RefusesInside extends CommandHandler
{
    public function ability(): ?string
    {
        return null;
    }

    public function handle(CommandContext $context): CommandOutcome
    {
        if (($context->params['mode'] ?? '') === 'validation') {
            throw ValidationException::withMessages(['body' => 'Resolve the blockers first.']);
        }

        throw CommandRefused::forbidden('Not while the kill switch is on.');
    }
}
