<?php

namespace ByteMinus\AiBlog\Commands;

use Illuminate\Console\Command;

class PublishScheduledCommand extends Command
{
    protected $signature = 'ai-blog:publish-scheduled';

    protected $description = 'Publish posts whose scheduled time has passed. Add to your scheduler: ->everyMinute()';

    public function handle(): int
    {
        $model = config('ai-blog.models.post');
        $due = $model::query()->dueForPublishing()->get();

        foreach ($due as $post) {
            $post->publish();
            $this->info("Published: {$post->title}");
        }

        $this->info($due->count().' post(s) published.');

        return self::SUCCESS;
    }
}
