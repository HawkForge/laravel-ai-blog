<?php

declare(strict_types=1);

namespace ByteMinus\AiBlog\Bus;

/**
 * What a caller asked for. Plain values only; nothing in here is trusted
 * until {@see CommandBus} has checked it.
 */
final readonly class CommandRequest
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public string $name,
        public string $idempotencyKey,
        public array $params = [],
        public bool $dryRun = false,
    ) {}
}
