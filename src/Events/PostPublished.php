<?php

namespace AiBlog\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

class PostPublished
{
    use Dispatchable;

    public function __construct(public Model $post) {}
}
