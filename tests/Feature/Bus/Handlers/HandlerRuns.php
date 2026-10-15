<?php

namespace ByteMinus\AiBlog\Tests\Feature\Bus\Handlers;

/** Counts how often a handler really ran, so a replay can be told from a run. */
final class HandlerRuns
{
    public static int $count = 0;
}
