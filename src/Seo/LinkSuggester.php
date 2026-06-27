<?php

namespace AiBlog\Seo;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Suggests internal links from one post to others, with anchor text.
 *
 * v1: TF-IDF cosine similarity over existing published posts — deterministic,
 * zero-cost, no API key needed. The AI layer (laravel/ai embeddings) plugs in
 * behind the same interface as a semantic upgrade.
 */
class LinkSuggester
{
    public function suggestFor(Model $post, ?int $max = null): Collection
    {
        $max ??= (int) config('ai-blog.seo.internal_links.max_suggestions', 5);
        $minScore = (float) config('ai-blog.seo.internal_links.min_score', 0.15);

        $candidates = $this->candidates($post);
        if ($candidates->isEmpty()) {
            return collect();
        }

        $docs = $candidates->map(fn (Model $p) => Text::termFrequency($this->textOf($p)))->all();
        $sourceTf = Text::termFrequency($this->textOf($post));
        $idf = $this->idf([...$docs, $sourceTf]);

        $sourceVec = $this->vector($sourceTf, $idf);
        $alreadyLinked = $this->existingLinkTargets($post);

        return $candidates
            ->map(function (Model $candidate, int $i) use ($docs, $idf, $sourceVec, $post) {
                $score = $this->cosine($sourceVec, $this->vector($docs[$i], $idf));

                return [
                    'post' => $candidate,
                    'score' => round($score, 3),
                    'anchor' => $this->anchorText($post, $candidate),
                ];
            })
            ->filter(fn ($s) => $s['score'] >= $minScore)
            ->reject(fn ($s) => in_array($s['post']->{$s['post']->getSlugField()}, $alreadyLinked, true))
            ->sortByDesc('score')
            ->take($max)
            ->values();
    }

    protected function candidates(Model $post): Collection
    {
        $query = $post->newQuery()->whereKeyNot($post->getKey());

        if (method_exists($post, 'scopePublished')) {
            $query->published();
        }

        return $query->get();
    }

    protected function textOf(Model $post): string
    {
        return $post->{$post->getTitleField()}.' '.$post->bodyText();
    }

    /** Pick anchor text: the candidate's title, or a phrase from the source that overlaps it. */
    protected function anchorText(Model $source, Model $candidate): string
    {
        $candidateTokens = array_flip(Text::tokens($candidate->{$candidate->getTitleField()}));
        $sourcePlain = Text::plain($source->bodyText());

        // Look for a run of 2–5 source words that overlaps the candidate's title tokens
        $words = preg_split('/\s+/u', $sourcePlain) ?: [];
        $bestRun = [];
        $run = [];
        foreach ($words as $w) {
            $norm = mb_strtolower(preg_replace('/[^\p{L}\p{N}]/u', '', $w) ?? '');
            if (isset($candidateTokens[$norm])) {
                $run[] = $w;
                if (count($run) > count($bestRun)) {
                    $bestRun = $run;
                }
            } else {
                $run = [];
            }
        }

        return count($bestRun) >= 2
            ? implode(' ', array_slice($bestRun, 0, 5))
            : (string) $candidate->{$candidate->getTitleField()};
    }

    /** @return array<int, string> slugs already linked from this post */
    protected function existingLinkTargets(Model $post): array
    {
        $body = $post->bodyText();
        $slugs = [];
        if (preg_match_all('/\]\(([^)]+)\)|href="([^"]+)"/i', $body, $m)) {
            foreach (array_merge($m[1], $m[2]) as $url) {
                if ($url !== '') {
                    $slugs[] = basename(parse_url($url, PHP_URL_PATH) ?: '');
                }
            }
        }

        return array_values(array_unique(array_filter($slugs)));
    }

    /** @param array<int, array<string,int>> $docs @return array<string,float> */
    private function idf(array $docs): array
    {
        $n = count($docs);
        $df = [];
        foreach ($docs as $tf) {
            foreach (array_keys($tf) as $term) {
                $df[$term] = ($df[$term] ?? 0) + 1;
            }
        }

        return array_map(fn ($d) => log(($n + 1) / ($d + 1)) + 1, $df);
    }

    /** @param array<string,int> $tf @param array<string,float> $idf @return array<string,float> */
    private function vector(array $tf, array $idf): array
    {
        $vec = [];
        foreach ($tf as $term => $count) {
            $vec[$term] = $count * ($idf[$term] ?? 1.0);
        }

        return $vec;
    }

    /** @param array<string,float> $a @param array<string,float> $b */
    private function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        foreach ($a as $t => $v) {
            if (isset($b[$t])) {
                $dot += $v * $b[$t];
            }
        }
        $na = sqrt(array_sum(array_map(fn ($v) => $v * $v, $a)));
        $nb = sqrt(array_sum(array_map(fn ($v) => $v * $v, $b)));

        return ($na == 0.0 || $nb == 0.0) ? 0.0 : $dot / ($na * $nb);
    }
}
