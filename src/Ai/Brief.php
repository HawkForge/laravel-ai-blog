<?php

namespace ByteMinus\AiBlog\Ai;

final class Brief
{
    /**
     * @param  array<int, string>  $outline  H2 headings in order
     * @param  array<int, string>  $questions  questions the post must answer
     * @param  array<int, string>  $relatedTerms  secondary keywords to work in
     */
    public function __construct(
        public readonly string $keyword,
        public readonly string $intent,
        public readonly string $audience,
        public readonly string $workingTitle,
        public readonly array $outline,
        public readonly array $questions = [],
        public readonly array $relatedTerms = [],
    ) {}

    public static function fromArray(array $a): self
    {
        return new self(
            keyword: (string) ($a['keyword'] ?? ''),
            intent: (string) ($a['intent'] ?? 'informational'),
            audience: (string) ($a['audience'] ?? ''),
            workingTitle: (string) ($a['working_title'] ?? $a['title'] ?? ''),
            outline: array_values((array) ($a['outline'] ?? [])),
            questions: array_values((array) ($a['questions'] ?? [])),
            relatedTerms: array_values((array) ($a['related_terms'] ?? [])),
        );
    }

    public function toArray(): array
    {
        return [
            'keyword' => $this->keyword,
            'intent' => $this->intent,
            'audience' => $this->audience,
            'working_title' => $this->workingTitle,
            'outline' => $this->outline,
            'questions' => $this->questions,
            'related_terms' => $this->relatedTerms,
        ];
    }
}
