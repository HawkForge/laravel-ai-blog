<?php

declare(strict_types=1);

namespace ByteMinus\AiBlog\Fetching;

/**
 * One site's robots.txt, read the way RFC 9309 says.
 *
 * The groups naming this fetcher's product token apply; when none does, the
 * `*` groups apply; when there are none of those either, everything is
 * allowed. Within the groups that apply the longest matching rule wins and a
 * tie goes to Allow. Patterns understand `*` and a trailing `$`. The file and
 * its rule count are bounded so a hostile robots.txt cannot cost much.
 */
final readonly class RobotsRules
{
    private const MAX_BYTES = 512 * 1024;

    private const MAX_RULES = 2000;

    private const MAX_PATTERN_LENGTH = 512;

    /**
     * @param  list<array{allow: bool, pattern: string}>  $rules
     */
    public function __construct(public array $rules = []) {}

    public static function allowAll(): self
    {
        return new self;
    }

    public static function disallowAll(): self
    {
        return new self([['allow' => false, 'pattern' => '/']]);
    }

    public static function parse(string $contents, string $productToken): self
    {
        $contents = substr($contents, 0, self::MAX_BYTES);
        $token = strtolower($productToken);

        /** @var list<array{allow: bool, pattern: string}> $specific */
        $specific = [];
        /** @var list<array{allow: bool, pattern: string}> $wildcard */
        $wildcard = [];

        $groupAgents = [];
        $inAgentRun = false;

        foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));

            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'user-agent') {
                if (! $inAgentRun) {
                    $groupAgents = [];
                }

                $groupAgents[] = strtolower((string) preg_split('#[/\s]#', $value)[0]);
                $inAgentRun = true;

                continue;
            }

            $inAgentRun = false;

            if (($field !== 'allow' && $field !== 'disallow') || $groupAgents === []) {
                continue;
            }

            if ($value === '' || strlen($value) > self::MAX_PATTERN_LENGTH) {
                continue;
            }

            $rule = ['allow' => $field === 'allow', 'pattern' => $value];

            foreach ($groupAgents as $agent) {
                if ($agent === '*') {
                    $wildcard[] = $rule;
                } elseif ($agent === $token) {
                    $specific[] = $rule;
                }
            }

            if (count($specific) + count($wildcard) >= self::MAX_RULES) {
                break;
            }
        }

        return new self($specific !== [] ? $specific : $wildcard);
    }

    /**
     * Whether a path (with its query string, percent-encoded as requested)
     * may be fetched.
     */
    public function allows(string $pathAndQuery): bool
    {
        if ($pathAndQuery === '/robots.txt') {
            return true;
        }

        $bestLength = -1;
        $allowed = true;

        foreach ($this->rules as $rule) {
            if (! $this->matches($rule['pattern'], $pathAndQuery)) {
                continue;
            }

            $length = strlen($rule['pattern']);

            if ($length > $bestLength || ($length === $bestLength && $rule['allow'])) {
                $bestLength = $length;
                $allowed = $rule['allow'];
            }
        }

        return $allowed;
    }

    private function matches(string $pattern, string $path): bool
    {
        $anchored = str_ends_with($pattern, '$');

        if ($anchored) {
            $pattern = substr($pattern, 0, -1);
        }

        $pattern = (string) preg_replace('/\*+/', '*', $pattern);
        $regex = implode('.*', array_map(
            fn (string $part): string => preg_quote($this->normaliseEncoding($part), '#'),
            explode('*', $pattern),
        ));

        return preg_match('#^'.$regex.($anchored ? '$' : '').'#', $this->normaliseEncoding($path)) === 1;
    }

    /**
     * Percent-escapes compared case-insensitively, and characters a URL may
     * carry either raw or escaped compared escaped.
     */
    private function normaliseEncoding(string $value): string
    {
        $value = (string) preg_replace_callback('/%[0-9a-fA-F]{2}/', fn (array $match): string => strtoupper($match[0]), $value);

        return (string) preg_replace_callback('/[^\x21-\x7E]/', fn (array $match): string => rawurlencode($match[0]), $value);
    }
}
