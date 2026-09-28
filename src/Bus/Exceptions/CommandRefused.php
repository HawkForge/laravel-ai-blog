<?php

declare(strict_types=1);

namespace AiBlog\Bus\Exceptions;

use RuntimeException;

/**
 * A command that must not run. The message is safe to show the caller; the
 * reason is one of forbidden, not_found, conflict or invalid, and maps to an
 * HTTP status.
 */
final class CommandRefused extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'invalid')
    {
        parent::__construct($message);
    }

    public static function unknown(string $name): self
    {
        return new self("There is no [{$name}] command.", 'not_found');
    }

    public static function missingAbility(?string $ability): self
    {
        return $ability === null
            ? new self('Only a person who may manage the blog can run this command.', 'forbidden')
            : new self("This credential does not carry the [{$ability}] permission.", 'forbidden');
    }

    public static function notFound(string $what): self
    {
        return new self("No such {$what}.", 'not_found');
    }

    public static function conflict(?string $message = null): self
    {
        return new self($message ?? 'This idempotency key was already used for a different request. Use a new key for a new request.', 'conflict');
    }

    public static function invalid(string $message): self
    {
        return new self($message, 'invalid');
    }

    public static function forbidden(string $message): self
    {
        return new self($message, 'forbidden');
    }

    public function httpStatus(): int
    {
        return match ($this->reason) {
            'forbidden' => 403,
            'not_found' => 404,
            'conflict' => 409,
            default => 422,
        };
    }
}
