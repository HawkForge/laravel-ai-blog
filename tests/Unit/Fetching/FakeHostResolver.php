<?php

declare(strict_types=1);

namespace AiBlog\Tests\Unit\Fetching;

use AiBlog\Fetching\HostResolver;

/**
 * A resolver that answers from a table, and can change its answer after the
 * first lookup the way a rebinding DNS server would.
 */
final class FakeHostResolver implements HostResolver
{
    /** @var array<string, int> */
    public array $lookups = [];

    /**
     * @param  array<string, list<string>>  $answers
     * @param  array<string, list<string>>  $laterAnswers  What a host resolves to from its second lookup on.
     */
    public function __construct(
        private array $answers = [],
        private array $laterAnswers = [],
    ) {}

    public function resolve(string $host): array
    {
        $this->lookups[$host] = ($this->lookups[$host] ?? 0) + 1;

        if ($this->lookups[$host] > 1 && isset($this->laterAnswers[$host])) {
            return $this->laterAnswers[$host];
        }

        return $this->answers[$host] ?? [];
    }
}
