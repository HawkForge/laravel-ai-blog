<?php

namespace AiBlog\Support;

use League\CommonMark\CommonMarkConverter;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final class Markdown
{
    public static function toHtml(string $markdown): string
    {
        static $converter = null;
        $converter ??= new CommonMarkConverter(['html_input' => 'allow', 'allow_unsafe_links' => false]);

        return (string) $converter->convert($markdown);
    }

    /** Markdown → HTML → sanitized. Use this for anything an AI or an agent wrote. */
    public static function toSafeHtml(string $markdown): string
    {
        static $sanitizer = null;
        $sanitizer ??= new HtmlSanitizer(
            (new HtmlSanitizerConfig)
                ->allowSafeElements()
                ->allowRelativeLinks()
                ->allowRelativeMedias()
                ->forceHttpsUrls(false)
                ->withMaxInputLength(2_000_000)
        );

        return $sanitizer->sanitize(self::toHtml($markdown));
    }
}
