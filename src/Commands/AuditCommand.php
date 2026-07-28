<?php

namespace AiBlog\Commands;

use AiBlog\AiBlog;
use Illuminate\Console\Command;

class AuditCommand extends Command
{
    protected $signature = 'ai-blog:audit
                            {--limit=50 : How many posts to audit}
                            {--fix : Fill in missing keyword and meta description with the AI assistant}
                            {--stale : Only show posts due for a refresh}';

    protected $description = 'Score every post for on-page SEO and list what to fix.';

    public function handle(AiBlog $aiBlog): int
    {
        $posts = $this->option('stale')
            ? $aiBlog->stale()
            : $aiBlog->posts()->latest()->limit((int) $this->option('limit'))->get();

        if ($posts->isEmpty()) {
            $this->info('No posts to audit.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($posts as $post) {
            if ($this->option('fix')) {
                $changed = $aiBlog->enrichSeo($post);
                if ($changed !== []) {
                    $this->line("  ↳ Enriched \"{$post->title}\": ".implode(', ', array_keys($changed)));
                }
            }

            $report = $aiBlog->score($post);
            $links = $aiBlog->suggestLinks($post, 3);

            $post->forceFill(['seo_score' => $report->score])->saveQuietly();

            $rows[] = [
                $post->getKey(),
                mb_strimwidth($post->title, 0, 40, '…'),
                $report->score.' ('.$report->grade().')',
                implode("\n", array_slice($report->fixes(), 0, 3)) ?: '—',
                $links->map(fn ($l) => $l['post']->title)->implode("\n") ?: '—',
            ];
        }

        $this->table(['ID', 'Title', 'Score', 'Top fixes', 'Link to'], $rows);

        return self::SUCCESS;
    }
}
