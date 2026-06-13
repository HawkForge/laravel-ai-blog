<?php

namespace AiBlog\Seo;

final class SeoCheck
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly bool $passed,
        public readonly int $weight,
        public readonly string $fix = '',
    ) {}

    public static function pass(string $key, string $label, int $weight): self
    {
        return new self($key, $label, true, $weight);
    }

    public static function fail(string $key, string $label, int $weight, string $fix): self
    {
        return new self($key, $label, false, $weight, $fix);
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'passed' => $this->passed,
            'weight' => $this->weight,
            'fix' => $this->fix,
        ];
    }
}
