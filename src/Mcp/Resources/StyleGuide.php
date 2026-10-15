<?php

namespace ByteMinus\AiBlog\Mcp\Resources;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

class StyleGuide extends Resource
{
    protected string $description = 'The site\'s house style for blog posts. Read before drafting or rewriting.';

    protected string $uri = 'ai-blog://style-guide';

    protected string $mimeType = 'text/plain';

    public function handle(Request $request): Response
    {
        return Response::text((string) config('ai-blog.ai.style_guide', ''));
    }
}
