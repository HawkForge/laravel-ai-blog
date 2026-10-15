<?php

declare(strict_types=1);

namespace ByteMinus\AiBlog\Bus;

/**
 * One command: the ability it needs, the params it takes, and a thin call
 * into an existing service. The ability, idempotency and the audit are the
 * bus's job, not the handler's.
 */
abstract class CommandHandler
{
    /**
     * The ability a credential needs. Null means only a person who may
     * manage the blog (or the package's own jobs) may run it.
     */
    abstract public function ability(): ?string;

    abstract public function handle(CommandContext $context): CommandOutcome;

    /**
     * Validation rules for the params. Empty passes the params through as
     * given, for a service that validates its own input.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * What a dry run returns: by default, that every check passed and what
     * would run. Nothing may change here.
     */
    public function preview(CommandContext $context): CommandOutcome
    {
        return CommandOutcome::completed(['dry_run' => true, 'would_run' => $context->command->name, 'checks' => 'passed']);
    }
}
