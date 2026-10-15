<?php

namespace ByteMinus\AiBlog\Seo;

/**
 * Small text utilities shared by the analyzer and link suggester.
 * Works on Markdown or HTML bodies.
 */
final class Text
{
    private const STOPWORDS = [
        'a', 'an', 'and', 'are', 'as', 'at', 'be', 'but', 'by', 'for', 'from', 'has', 'have', 'if', 'in', 'into', 'is',
        'it', 'its', 'no', 'not', 'of', 'on', 'or', 'such', 'that', 'the', 'their', 'then', 'there', 'these', 'they',
        'this', 'to', 'was', 'we', 'were', 'will', 'with', 'you', 'your', 'i', 'he', 'she', 'his', 'her', 'our', 'can',
        'do', 'does', 'how', 'what', 'when', 'where', 'which', 'who', 'why', 'all', 'also', 'more', 'most', 'some',
        'than', 'too', 'very', 'just', 'about', 'after', 'before', 'over', 'under', 'up', 'out', 'so', 'get', 'use',
    ];

    public static function plain(string $input): string
    {
        $s = preg_replace('/```.*?```/s', ' ', $input) ?? $input;   // fenced code
        $s = preg_replace('/`[^`]*`/', ' ', $s) ?? $s;               // inline code
        $s = preg_replace('/!\[[^\]]*\]\([^)]*\)/', ' ', $s) ?? $s;  // md images
        $s = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $s) ?? $s; // md links -> text
        $s = strip_tags($s);
        $s = preg_replace('/^#{1,6}\s*/m', '', $s) ?? $s;            // md headings
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5);

        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }

    public static function wordCount(string $input): int
    {
        $plain = self::plain($input);

        return $plain === '' ? 0 : count(preg_split('/\s+/u', $plain) ?: []);
    }

    /** @return array<int, string> */
    public static function headings(string $input, int $level): array
    {
        $out = [];

        // Markdown
        if (preg_match_all('/^'.str_repeat('#', $level).'(?!#)\s*(.+)$/m', $input, $m)) {
            $out = array_merge($out, $m[1]);
        }

        // HTML
        if (preg_match_all('/<h'.$level.'[^>]*>(.*?)<\/h'.$level.'>/is', $input, $m)) {
            $out = array_merge($out, array_map('strip_tags', $m[1]));
        }

        return array_map('trim', $out);
    }

    public static function firstParagraph(string $input, int $words = 100): string
    {
        $plain = self::plain($input);
        $tokens = preg_split('/\s+/u', $plain) ?: [];

        return implode(' ', array_slice($tokens, 0, $words));
    }

    /** @return array<int, string> */
    public static function imagesMissingAlt(string $input): array
    {
        $missing = [];

        if (preg_match_all('/!\[([^\]]*)\]\(([^)]+)\)/', $input, $m, PREG_SET_ORDER)) {
            foreach ($m as $img) {
                if (trim($img[1]) === '') {
                    $missing[] = $img[2];
                }
            }
        }

        if (preg_match_all('/<img\b[^>]*>/i', $input, $m)) {
            foreach ($m[0] as $tag) {
                if (! preg_match('/\balt\s*=\s*"[^"]+"/i', $tag) && ! preg_match("/\\balt\\s*=\\s*'[^']+'/i", $tag)) {
                    $missing[] = $tag;
                }
            }
        }

        return $missing;
    }

    public static function linkCount(string $input): int
    {
        $md = preg_match_all('/(?<!!)\[[^\]]+\]\([^)]+\)/', $input);
        $html = preg_match_all('/<a\b[^>]*href=/i', $input);

        return (int) $md + (int) $html;
    }

    public static function contains(string $haystack, string $needle): bool
    {
        return $needle !== '' && mb_stripos(self::plain($haystack), $needle) !== false;
    }

    /** Lower-cased, stopword-free tokens. @return array<int, string> */
    public static function tokens(string $input): array
    {
        $plain = mb_strtolower(self::plain($input));
        $plain = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $plain) ?? $plain;
        $tokens = preg_split('/\s+/u', trim($plain)) ?: [];

        return array_values(array_filter($tokens, fn ($t) => mb_strlen($t) > 2 && ! in_array($t, self::STOPWORDS, true)));
    }

    /** Term frequency map. @return array<string, int> */
    public static function termFrequency(string $input): array
    {
        return array_count_values(self::tokens($input));
    }

    /** Flesch reading ease, approximate (English). Higher is easier. */
    public static function readingEase(string $input): float
    {
        $plain = self::plain($input);
        if ($plain === '') {
            return 0.0;
        }

        $sentences = max(1, preg_match_all('/[.!?]+(\s|$)/u', $plain));
        $words = preg_split('/\s+/u', $plain) ?: [];
        $wordCount = max(1, count($words));
        $syllables = array_sum(array_map([self::class, 'syllables'], $words));

        return round(206.835 - 1.015 * ($wordCount / $sentences) - 84.6 * ($syllables / $wordCount), 1);
    }

    private static function syllables(string $word): int
    {
        $word = mb_strtolower(preg_replace('/[^a-z]/i', '', $word) ?? '');
        if ($word === '') {
            return 0;
        }
        $word = preg_replace('/(?:[^laeiouy]es|ed|[^laeiouy]e)$/', '', $word) ?? $word;
        $word = preg_replace('/^y/', '', $word) ?? $word;

        return max(1, preg_match_all('/[aeiouy]{1,2}/', $word));
    }
}
