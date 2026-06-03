<?php

namespace AiBlog\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

class PostScheduled
{
    use Dispatchable;

    public function __construct(public Model $post) {}
}
