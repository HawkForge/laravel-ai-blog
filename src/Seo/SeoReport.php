<?php

namespace ByteMinus\AiBlog\Seo;

use Illuminate\Contracts\Support\Arrayable;

final class SeoReport implements Arrayable
{
    /** @param array<int, SeoCheck> $checks */
    public function __construct(
        public readonly int $score,
        public readonly array $checks,
    ) {}

    /** @return array<int, SeoCheck> */
    public function failures(): array
    {
        return array_values(array_filter($this->checks, fn (SeoCheck $c) => ! $c->passed));
    }

    /** @return array<int, string> */
    public function fixes(): array
    {
        return array_map(fn (SeoCheck $c) => $c->fix, $this->failures());
    }

    public function grade(): string
    {
        return match (true) {
            $this->score >= 90 => 'A',
            $this->score >= 75 => 'B',
            $this->score >= 60 => 'C',
            $this->score >= 40 => 'D',
            default => 'F',
        };
    }

    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'grade' => $this->grade(),
            'checks' => array_map(fn (SeoCheck $c) => $c->toArray(), $this->checks),
            'fixes' => $this->fixes(),
        ];
    }
}
