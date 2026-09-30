<?php

declare(strict_types=1);

namespace AiBlog\Bus;

use AiBlog\Bus\Exceptions\CommandRefused;
use InvalidArgumentException;

/**
 * Every command there is, by name. A name not listed here does not exist,
 * whoever asks for it. The package registers its own handlers; a host adds
 * more under `ai-blog.commands`.
 */
final class CommandRegistry
{
    /** @var array<string, class-string<CommandHandler>> */
    private array $handlers = [];

    /**
     * @param  array<string, class-string<CommandHandler>>  $handlers
     */
    public function __construct(array $handlers = [])
    {
        foreach ($handlers as $name => $class) {
            $this->register($name, $class);
        }
    }

    /**
     * @param  class-string<CommandHandler>  $class
     */
    public function register(string $name, string $class): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $name) !== 1 || mb_strlen($name) > 64) {
            throw new InvalidArgumentException("[{$name}] is not a valid command name.");
        }

        if (! is_subclass_of($class, CommandHandler::class)) {
            throw new InvalidArgumentException("[{$class}] is not a command handler.");
        }

        $this->handlers[$name] = $class;
    }

    /**
     * @throws CommandRefused
     */
    public function handler(string $name): CommandHandler
    {
        $class = $this->handlers[$name] ?? throw CommandRefused::unknown(mb_substr($name, 0, 64));

        return app($class);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->handlers);
    }
}
