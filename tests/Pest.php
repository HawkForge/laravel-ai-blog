<?php

use ByteMinus\AiBlog\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * A reasonably well-optimized post body for a given keyword.
 */
function goodBody(string $keyword = 'laravel queues'): string
{
    $kw = $keyword;
    $filler = str_repeat('This sentence adds useful detail for the reader and keeps things simple. ', 11);

    return <<<MD
    Learning {$kw} is the fastest way to make a Laravel app feel instant. {$filler}

    ## Why {$kw} matter

    {$filler} See [the docs](https://laravel.com/docs/queues) and [our intro](/blog/laravel-basics).

    ## Setting up {$kw}

    ![A worker processing jobs]({$kw}.png)

    {$filler}

    ## Common mistakes

    {$filler}

    ## Is {$kw} hard?

    No. {$filler}
    MD;
}
