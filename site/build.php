<?php

use League\CommonMark\GithubFlavoredMarkdownConverter;

/*
 * Builds the website from landing.html and the package's own Markdown docs.
 *
 *     php site/build.php
 *     SITE_URL=https://blog.example.com php site/build.php
 *
 * Writes site/public/: the landing page, one HTML page per doc, and the AI layer
 * (llms.txt, llms-full.txt, a Markdown copy of every page), plus robots.txt,
 * sitemap.xml and 404.html. Every internal link carries the base path, so the
 * site works at a domain root or under a GitHub Pages project path.
 */

$root = dirname(__DIR__);
$out = __DIR__.'/public';

$siteUrl = rtrim(getenv('SITE_URL') ?: 'https://byteminus.github.io/laravel-ai-blog', '/').'/';
$base = rtrim(parse_url($siteUrl, PHP_URL_PATH) ?: '/', '/').'/';
$repo = 'https://github.com/byteminus/laravel-ai-blog';
$package = 'byteminus/laravel-ai-blog';
$today = date('Y-m-d');

require $root.'/vendor/autoload.php';

$e = fn (string $s) => htmlspecialchars($s, ENT_QUOTES);

// ---------------------------------------------------------------------------
// Landing page source
// ---------------------------------------------------------------------------

$source = file_get_contents(__DIR__.'/landing.html');
$body = preg_replace('#<title>.*?</title>\s*#s', '', $source);
preg_match_all('#<link[^>]+>\s*#', $body, $links);
preg_match('#<style>.*?</style>#s', $body, $style);
$body = trim(str_replace(array_merge($links[0], [$style[0] ?? '']), '', $body));
// Fonts load without blocking the first paint; the system fallback shows until they arrive.
$fontLinks = preg_replace_callback(
    '#<link rel="stylesheet" href="([^"]+)">#',
    fn ($m) => "<link rel=\"preload\" as=\"style\" href=\"{$m[1]}\">\n"
        ."<link rel=\"stylesheet\" href=\"{$m[1]}\" media=\"print\" onload=\"this.media='all'\">\n"
        ."<noscript><link rel=\"stylesheet\" href=\"{$m[1]}\"></noscript>",
    implode('', $links[0]),
);
$css = $style[0] ?? '';

// The landing page links relatively; root-relative links would break under a project path.
if (preg_match('#(href|src)="/(?!/)#', $body)) {
    fwrite(STDERR, "landing.html has a root-relative link; use a relative one.\n");
    exit(1);
}

// FAQ structured data, read from the page itself so the two never drift apart.
preg_match_all('#<details[^>]*><summary>(.*?)</summary><p>(.*?)</p></details>#s', $body, $faqs, PREG_SET_ORDER);
$faqEntities = array_map(fn ($m) => [
    '@type' => 'Question',
    'name' => html_entity_decode(strip_tags($m[1])),
    'acceptedAnswer' => ['@type' => 'Answer', 'text' => html_entity_decode(strip_tags($m[2]))],
], $faqs);

// ---------------------------------------------------------------------------
// Docs: the README split into focused pages, plus the project files
// ---------------------------------------------------------------------------

$readme = file_get_contents($root.'/README.md');
$parts = preg_split('/^## /m', $readme);
$intro = trim(preg_replace(['/^# .*$/m', '/^\[!\[.*$/m', '/^\*\*Website and docs:\*\*.*$/m'], '', array_shift($parts)));
$sections = [];

foreach ($parts as $part) {
    [$heading, $text] = explode("\n", $part, 2) + [1 => ''];
    $sections[trim($heading)] = trim($text);
}

$docDefs = [
    'getting-started' => [
        'nav' => 'Getting started', 'group' => 'Guides',
        'h1' => 'Getting started with Laravel AI Blog',
        'title' => 'Getting Started: Install the Laravel Blog Package',
        'description' => 'Install Laravel AI Blog, run the migrations and score your first post for SEO. Requirements, setup and a 60-second tour of the API.',
        'intro' => true, 'sections' => ['Requirements', 'Install', '60-second tour'],
    ],
    'existing-post-model' => [
        'nav' => 'Use your Post model', 'group' => 'Guides',
        'h1' => 'Use your existing Post model',
        'title' => 'Add SEO Scoring to Your Existing Laravel Post Model',
        'description' => 'Keep your own Eloquent Post model: add the HasBlogSeo trait, map your field names and get SEO scores and internal links on it.',
        'sections' => ['Already have a Post model?'],
    ],
    'mcp-server' => [
        'nav' => 'MCP server for agents', 'group' => 'Guides',
        'h1' => 'Let AI agents write your blog over MCP',
        'title' => 'Connect Claude and ChatGPT to a Laravel Blog (MCP)',
        'description' => 'Enable the built-in MCP server so Claude Code, Claude.ai and ChatGPT can draft, score, link and schedule posts. Agents cannot publish.',
        'sections' => ['Let agents write your blog'],
    ],
    'seo-routes' => [
        'nav' => 'Blog routes and SEO tags', 'group' => 'Guides',
        'h1' => 'Blog routes, SEO tags and scheduling',
        'title' => 'Laravel Blog Routes, SEO Meta Tags and JSON-LD',
        'description' => 'Turn on /blog routes, Blade components and RSS. See every canonical, Open Graph and JSON-LD tag the pages send, and schedule publishing.',
        'sections' => ['Public routes (opt-in)', 'Scheduler'],
    ],
    'safe-fetching' => [
        'nav' => 'Safe fetching', 'group' => 'Guides',
        'h1' => 'Safe fetching for AI research',
        'title' => 'Safe Web Fetching for AI Research in Laravel',
        'description' => 'SafeFetcher reads web pages and feeds for AI research while blocking SSRF: public addresses only, pinned DNS, robots.txt and size limits.',
        'sections' => ['Safe fetching (research)'],
    ],
    'command-bus' => [
        'nav' => 'Command bus', 'group' => 'Guides',
        'h1' => 'Command bus, idempotency and audit trail',
        'title' => 'Command Bus with Idempotency and an Audit Trail',
        'description' => 'Route every blog change through one command bus: abilities, validation, idempotency keys that replay retries, dry runs and an audit trail.',
        'sections' => ['Command bus'],
    ],
    'faq' => [
        'nav' => 'FAQ', 'group' => 'Guides',
        'h1' => 'Laravel AI Blog FAQ',
        'title' => 'Laravel AI Blog FAQ: Install, SEO, AI, MCP',
        'description' => 'Answers to every common question about Laravel AI Blog: installation, SEO scoring, AI drafting, the MCP server, routes, safe fetching and testing.',
        'sections' => ['FAQ'],
        'faqFile' => __DIR__.'/faq.php',
    ],
    'philosophy' => [
        'nav' => 'Philosophy and roadmap', 'group' => 'Project',
        'h1' => 'Philosophy and roadmap',
        'title' => 'Philosophy and Roadmap',
        'description' => 'Why Laravel AI Blog makes human writers faster instead of mass-producing posts, and what is planned next: sitemaps, embeddings, Filament.',
        'sections' => ['Philosophy', 'Roadmap'],
    ],
    'contributing' => [
        'nav' => 'Contributing', 'group' => 'Project',
        'h1' => 'Contributing to Laravel AI Blog',
        'title' => 'Contributing',
        'description' => 'Set up Laravel AI Blog locally, run the tests and static analysis, and send a pull request. Good first issues are listed for most roadmap items.',
        'file' => 'CONTRIBUTING.md',
    ],
    'security' => [
        'nav' => 'Security', 'group' => 'Project',
        'h1' => 'Security policy',
        'title' => 'Security Policy',
        'description' => 'How to report a security vulnerability in Laravel AI Blog privately, and which versions receive security fixes.',
        'file' => 'SECURITY.md',
    ],
    'changelog' => [
        'nav' => 'Changelog', 'group' => 'Project',
        'h1' => 'Changelog',
        'title' => 'Changelog',
        'description' => 'Every release of Laravel AI Blog with what changed: SEO tags, AI features, MCP tools, safe fetching and breaking changes.',
        'file' => 'CHANGELOG.md',
    ],
];

$fileSlugs = [];
foreach ($docDefs as $slug => $def) {
    if (isset($def['file'])) {
        $fileSlugs[$def['file']] = $slug;
    }
}

// Markdown for one page. Links to repo files become site links ($absolute) so
// the Markdown copies work on their own; the HTML renderer rewrites them again.
$relink = function (string $text) use ($fileSlugs, $siteUrl, $repo): string {
    return preg_replace_callback('#\]\(([A-Z]+\.md)\)#', function ($m) use ($fileSlugs, $siteUrl, $repo) {
        return isset($fileSlugs[$m[1]]) ? "]({$siteUrl}docs/{$fileSlugs[$m[1]]}/)" : "]({$repo}/blob/main/{$m[1]})";
    }, $text);
};

// The full FAQ lives in faq.php as [group => [[question, answer], ...]]; answers use backticks for code.
$faqGroups = is_file(__DIR__.'/faq.php') ? require __DIR__.'/faq.php' : [];
$faqCount = array_sum(array_map('count', $faqGroups));
$plain = fn (string $s) => str_replace('`', '', $s);

foreach ($docDefs as $slug => &$def) {
    if (isset($def['faqFile']) && $faqGroups !== []) {
        $text = "Answers to {$faqCount} questions developers and AI assistants ask about Laravel AI Blog (`{$package}`), checked against the package source.\n\n"
            .implode("\n\n", array_map(
                fn ($group, $items) => "## {$group}\n\n".implode("\n\n", array_map(fn ($qa) => "### {$qa[0]}\n\n{$qa[1]}", $items)),
                array_keys($faqGroups),
                $faqGroups,
            ));
        $def['source'] = 'site/faq.php';
        $def['nav'] = "FAQ ({$faqCount} questions)";
    } elseif (isset($def['file'])) {
        $text = trim(preg_replace('/\A# .*\n+/', '', file_get_contents($root.'/'.$def['file'])));
        $def['source'] = $def['file'];
    } else {
        $chunks = [];
        $single = count($def['sections']) === 1 && empty($def['intro']);

        foreach ($def['sections'] as $heading) {
            if (! isset($sections[$heading])) {
                fwrite(STDERR, "README section \"{$heading}\" not found.\n");
                exit(1);
            }

            $chunks[] = ($single ? '' : "## {$heading}\n\n").$sections[$heading];
        }

        $text = (! empty($def['intro']) ? $intro."\n\n" : '').implode("\n\n", $chunks);
        $def['source'] = 'README.md';
    }

    $def['markdown'] = "# {$def['h1']}\n\n".$relink($text);

    foreach (['title' => 60, 'description' => 160] as $field => $max) {
        $length = mb_strlen($field === 'title' ? $def['title'].' | Laravel AI Blog' : $def['description']);
        if ($field === 'title' && $length > $max && mb_strlen($def['title']) <= $max) {
            $def['fullTitle'] = $def['title']; // the brand suffix would push it over; the title stands alone
        }
        if ($field === 'description' && ($length < 110 || $length > $max)) {
            fwrite(STDERR, "Warning: {$slug} description is {$length} characters.\n");
        }
    }
    $def['fullTitle'] ??= $def['title'].' | Laravel AI Blog';
}
unset($def);

// ---------------------------------------------------------------------------
// Shared head
// ---------------------------------------------------------------------------

$homeTitle = 'Laravel AI Blog: SEO Blog Package with AI and MCP';
$homeDescription = 'Free Laravel blog package with on-page SEO scoring, internal link suggestions, AI drafting via laravel/ai and an MCP server for Claude and ChatGPT.';
$keywords = 'laravel blog package, laravel blog, laravel seo, laravel cms, laravel ai, laravel mcp server, ai blog writer, seo score, internal linking, json-ld, headless blog laravel';
$ogImage = $siteUrl.'og.png';

$org = ['@type' => 'Organization', '@id' => $siteUrl.'#org', 'name' => 'ByteMinus', 'url' => 'https://github.com/byteminus',
    'logo' => $siteUrl.'apple-touch-icon.png'];

$head = function (array $p) use ($e, $siteUrl, $base, $ogImage, $fontLinks, $css, $keywords): string {
    $robots = ($p['noindex'] ?? false) ? 'noindex, follow' : 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1';
    $ld = isset($p['ld']) ? '<script type="application/ld+json">'.json_encode($p['ld'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."</script>\n" : '';
    $type = $p['type'] ?? 'article';

    return "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n"
        ."<meta name=\"viewport\" content=\"width=device-width, initial-scale=1, viewport-fit=cover\">\n"
        ."<title>{$e($p['title'])}</title>\n"
        ."<meta name=\"description\" content=\"{$e($p['description'])}\">\n"
        .(($p['keywords'] ?? false) ? "<meta name=\"keywords\" content=\"{$e($keywords)}\">\n" : '')
        ."<meta name=\"robots\" content=\"{$robots}\">\n"
        ."<meta name=\"author\" content=\"ByteMinus\">\n"
        .(isset($p['url']) ? "<link rel=\"canonical\" href=\"{$e($p['url'])}\">\n" : '')
        .(isset($p['md']) ? "<link rel=\"alternate\" type=\"text/markdown\" href=\"{$e($p['md'])}\" title=\"This page in Markdown\">\n" : '')
        ."<link rel=\"describedby\" href=\"{$siteUrl}llms.txt\" type=\"text/plain\">\n"
        ."<link rel=\"sitemap\" type=\"application/xml\" href=\"{$siteUrl}sitemap.xml\">\n"
        ."<meta name=\"theme-color\" content=\"#f8f6f1\" media=\"(prefers-color-scheme: light)\">\n"
        ."<meta name=\"theme-color\" content=\"#121118\" media=\"(prefers-color-scheme: dark)\">\n"
        ."<meta name=\"color-scheme\" content=\"light dark\">\n"
        ."<meta property=\"og:type\" content=\"{$type}\">\n"
        ."<meta property=\"og:site_name\" content=\"Laravel AI Blog\">\n"
        ."<meta property=\"og:locale\" content=\"en_US\">\n"
        ."<meta property=\"og:title\" content=\"{$e($p['title'])}\">\n"
        ."<meta property=\"og:description\" content=\"{$e($p['description'])}\">\n"
        .(isset($p['url']) ? "<meta property=\"og:url\" content=\"{$e($p['url'])}\">\n" : '')
        ."<meta property=\"og:image\" content=\"{$ogImage}\">\n"
        ."<meta property=\"og:image:type\" content=\"image/png\">\n"
        ."<meta property=\"og:image:width\" content=\"1200\">\n<meta property=\"og:image:height\" content=\"630\">\n"
        ."<meta property=\"og:image:alt\" content=\"Laravel AI Blog: SEO scoring, AI drafting and an MCP server for Laravel\">\n"
        .(isset($p['modified']) ? "<meta property=\"article:modified_time\" content=\"{$p['modified']}\">\n" : '')
        ."<meta name=\"twitter:card\" content=\"summary_large_image\">\n"
        ."<meta name=\"twitter:title\" content=\"{$e($p['title'])}\">\n"
        ."<meta name=\"twitter:description\" content=\"{$e($p['description'])}\">\n"
        ."<meta name=\"twitter:image\" content=\"{$ogImage}\">\n"
        ."<link rel=\"icon\" href=\"{$base}favicon.svg\" type=\"image/svg+xml\">\n"
        ."<link rel=\"apple-touch-icon\" href=\"{$base}apple-touch-icon.png\">\n"
        .$fontLinks
        .$ld
        ."<style>*,*::before,*::after{box-sizing:border-box}body{margin:0}img{max-width:100%;height:auto}[hidden]{display:none!important}</style>\n"
        .$css."\n".($p['css'] ?? '')."\n</head>\n<body>\n";
};

$breadcrumbs = fn (array $items) => [
    '@type' => 'BreadcrumbList',
    'itemListElement' => array_map(fn ($i, $item) => ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $item[0], 'item' => $item[1]], array_keys($items), $items),
];

// ---------------------------------------------------------------------------
// Landing page
// ---------------------------------------------------------------------------

$homeLd = ['@context' => 'https://schema.org', '@graph' => [
    ['@type' => 'WebSite', '@id' => $siteUrl.'#website', 'name' => 'Laravel AI Blog', 'url' => $siteUrl, 'inLanguage' => 'en', 'publisher' => ['@id' => $siteUrl.'#org']],
    ['@type' => 'WebPage', '@id' => $siteUrl.'#webpage', 'url' => $siteUrl, 'name' => $homeTitle, 'description' => $homeDescription,
        'isPartOf' => ['@id' => $siteUrl.'#website'], 'about' => ['@id' => $siteUrl.'#software'], 'dateModified' => $today,
        'primaryImageOfPage' => ['@type' => 'ImageObject', 'url' => $ogImage, 'width' => 1200, 'height' => 630]],
    ['@type' => 'SoftwareSourceCode', '@id' => $siteUrl.'#software', 'name' => 'Laravel AI Blog', 'alternateName' => $package,
        'description' => $homeDescription, 'url' => $siteUrl, 'codeRepository' => $repo, 'programmingLanguage' => 'PHP',
        'runtimePlatform' => 'Laravel 10, 11, 12, 13 on PHP 8.2 to 8.5', 'license' => 'https://opensource.org/licenses/MIT',
        'keywords' => $keywords, 'author' => ['@id' => $siteUrl.'#org'], 'image' => $ogImage],
    $org,
    ['@type' => 'FAQPage', '@id' => $siteUrl.'#faq', 'mainEntity' => $faqEntities],
]];

$home = $head([
    'title' => $homeTitle, 'description' => $homeDescription, 'url' => $siteUrl, 'md' => $siteUrl.'index.md',
    'type' => 'website', 'keywords' => true, 'ld' => $homeLd,
]).$body."\n</body>\n</html>\n";

// ---------------------------------------------------------------------------
// Docs pages
// ---------------------------------------------------------------------------

$converter = new GithubFlavoredMarkdownConverter([
    'html_input' => 'escape',
    'allow_unsafe_links' => false,
]);

$docsCss = <<<'CSS'
<style>
.docs-main .wrap { display: grid; grid-template-columns: 230px minmax(0, 1fr); gap: clamp(24px, 4vw, 56px); padding-block: 36px 88px; }
@media (max-width: 860px) { .docs-main .wrap { grid-template-columns: minmax(0, 1fr); } }
.docnav { font-size: 0.92rem; position: sticky; top: calc(env(safe-area-inset-top, 0px) + 76px); align-self: start; max-height: calc(100vh - 100px); overflow-y: auto; }
@media (max-width: 860px) { .docnav { position: static; max-height: none; border-bottom: 1px solid var(--line); padding-bottom: 16px; } }
.docnav p { margin: 18px 0 6px; font: 600 0.72rem var(--font-mono); letter-spacing: 0.08em; text-transform: uppercase; color: var(--muted); }
.docnav p:first-child { margin-top: 0; }
.docnav ul { list-style: none; margin: 0; padding: 0; display: grid; gap: 2px; }
@media (max-width: 860px) { .docnav ul { display: flex; flex-wrap: wrap; gap: 4px 14px; } }
.docnav a { display: block; color: var(--ink); text-decoration: none; padding: 4px 10px; border-radius: 6px; }
.docnav a:hover { background: var(--mark-soft); }
.docnav a[aria-current="page"] { background: var(--mark-soft); color: var(--mark); font-weight: 600; }
.crumbs { font-size: var(--step--1); color: var(--muted); margin-bottom: 14px; }
.crumbs ol { list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: 6px; }
.crumbs li + li::before { content: "/"; margin-right: 6px; }
.crumbs a { color: var(--muted); }
.prose { min-width: 0; max-width: 76ch; }
.prose h1 { font-size: var(--step-3); font-weight: 800; margin-bottom: 18px; }
.prose h2 { font-size: var(--step-2); font-weight: 800; margin: 2.2em 0 0.6em; }
.prose h3 { font-size: var(--step-1); font-weight: 800; margin: 1.8em 0 0.5em; }
.prose p, .prose ul, .prose ol { margin: 0 0 1em; }
.prose li { margin: 0.25em 0; }
.prose pre { background: var(--code-bg); color: var(--code-ink); padding: 18px 20px; border-radius: var(--radius); overflow-x: auto; margin: 0 0 1.4em; font-size: 0.86rem; line-height: 1.6; }
.prose pre code { background: none; padding: 0; color: inherit; overflow-wrap: normal; }
.prose table { display: block; overflow-x: auto; min-width: 0; margin: 0 0 1.4em; border: 1px solid var(--line); border-radius: var(--radius); font-size: 0.92rem; }
.prose th, .prose td { padding: 9px 14px; }
.prose blockquote { margin: 0 0 1em; padding: 4px 16px; border-left: 3px solid var(--mark); color: var(--muted); }
.prose hr { border: 0; border-top: 1px solid var(--line); margin: 2em 0; }
.prose input[type=checkbox] { margin-right: 6px; }
.prose li:has(> input[type=checkbox]) { list-style: none; margin-left: -1.2em; }
.updated { color: var(--muted); font-size: var(--step--1); margin: -8px 0 24px; }
.docfoot { margin-top: 48px; padding-top: 18px; border-top: 1px solid var(--line); display: flex; flex-wrap: wrap; gap: 8px 20px; font-size: var(--step--1); color: var(--muted); }
.pager { display: flex; justify-content: space-between; gap: 12px; margin-top: 32px; flex-wrap: wrap; }
.pager a { border: 1px solid var(--line); border-radius: var(--radius); padding: 10px 16px; text-decoration: none; color: var(--ink); background: var(--panel); }
.pager a:hover { border-color: var(--mark); }
.pager small { display: block; color: var(--muted); font-size: 0.78rem; }
.cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 12px; margin: 0 0 2em; }
.cards a { display: block; border: 1px solid var(--line); border-radius: var(--radius); padding: 14px 16px; text-decoration: none; color: var(--ink); background: var(--panel); }
.cards a:hover { border-color: var(--mark); }
.cards b { display: block; font-family: var(--font-display); font-size: 1.1rem; }
.cards span { color: var(--muted); font-size: 0.86rem; }
</style>
CSS;

$siteHeader = <<<HTML
<a class="skip" href="#main">Skip to content</a>
<header class="top">
  <div class="wrap">
    <a class="brand" href="{$base}"><b>AI<i>/</i>Blog</b><small>for Laravel</small></a>
    <nav aria-label="Site">
      <a href="{$base}#features">Features</a>
      <a href="{$base}#seo">SEO score</a>
      <a href="{$base}#agents">Agents</a>
      <a class="keep" href="{$base}docs/">Docs</a>
      <a class="keep" href="{$repo}">GitHub</a>
    </nav>
  </div>
</header>
HTML;

$siteFooter = '<footer class="foot"><div class="wrap"><span>Laravel AI Blog · SEO blog package for Laravel · MIT license</span>'
    .'<span><a href="'.$base.'docs/">Docs</a> · <a href="https://packagist.org/packages/'.$package.'">Packagist</a> · For AI assistants: <a href="'.$base.'llms.txt">llms.txt</a> · <a href="'.$base.'llms-full.txt">full docs</a> · By <a href="https://github.com/byteminus">ByteMinus</a></span></div></footer>';

$navHtml = function (string $current) use ($docDefs, $base, $e): string {
    $html = '<nav class="docnav" aria-label="Documentation">';
    $group = null;

    foreach ($docDefs as $slug => $def) {
        if ($def['group'] !== $group) {
            $html .= ($group === null ? '' : '</ul>').'<p>'.$def['group'].'</p><ul>';
            $group = $def['group'];
        }

        $here = $slug === $current ? ' aria-current="page"' : '';
        $html .= "<li><a href=\"{$base}docs/{$slug}/\"{$here}>".$e($def['nav']).'</a></li>';
    }

    return $html.'</ul></nav>';
};

// Site links in rendered HTML become base-relative so they survive a domain change.
$localLinks = fn (string $html) => str_replace('href="'.$siteUrl, 'href="'.$base, $html);

$crumbHtml = function (array $items) use ($e): string {
    $html = '<nav class="crumbs" aria-label="Breadcrumb"><ol>';
    foreach ($items as $i => [$name, $href]) {
        $html .= $i === array_key_last($items) ? '<li aria-current="page">'.$e($name).'</li>' : '<li><a href="'.$href.'">'.$e($name).'</a></li>';
    }

    return $html.'</ol></nav>';
};

// When a page last really changed: the last commit that touched its source file.
$gitDate = fn (string $path) => trim((string) shell_exec('git -C '.escapeshellarg($root).' log -1 --format=%cs -- '.escapeshellarg($path).' 2>/dev/null')) ?: $today;

$slugs = array_keys($docDefs);
$pages = [];
$diagrams = require __DIR__.'/diagrams.php';

// Every H2 and H3 gets a stable id, so people and agents can link to one answer.
$anchorHeadings = function (string $html): string {
    $seen = [];

    return preg_replace_callback('#<h([23])>(.*?)</h\1>#s', function ($m) use (&$seen) {
        $id = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(html_entity_decode(strip_tags($m[2])))), '-');
        $id = isset($seen[$id]) ? $id.'-'.(++$seen[$id]) : $id;
        $seen[$id] ??= 1;

        return "<h{$m[1]} id=\"{$id}\">{$m[2]}</h{$m[1]}>";
    }, $html);
};

foreach ($docDefs as $slug => $def) {
    $url = "{$siteUrl}docs/{$slug}/";
    $modified = $gitDate($def['source']);
    $html = $anchorHeadings($localLinks($converter->convert($def['markdown'])->getContent()));
    $i = array_search($slug, $slugs, true);
    $prev = $slugs[$i - 1] ?? null;
    $next = $slugs[$i + 1] ?? null;

    if (isset($diagrams[$slug])) {
        $d = $diagrams[$slug];

        if (! str_contains($html, $d['before'])) {
            fwrite(STDERR, "Diagram anchor for {$slug} not found: {$d['before']}\n");
            exit(1);
        }

        $figure = '<figure class="dg"><div class="scroll">'.str_replace('%alt%', $e($d['alt']), $d['svg']).'</div>'
            .'<figcaption>'.$e($d['caption']).'</figcaption></figure>';
        $html = preg_replace('#'.preg_quote($d['before'], '#').'#', $figure.$d['before'], $html, 1);
        $docDefs[$slug]['markdown'] = preg_replace('/\A(# .*\n)/', "$1\n> **Diagram:** {$d['alt']}\n", $def['markdown']);
    }

    $faqLd = isset($def['faqFile']) && $faqGroups !== [] ? [[
        '@type' => 'FAQPage', '@id' => $url.'#faq',
        'mainEntity' => array_merge(...array_map(fn ($items) => array_map(fn ($qa) => [
            '@type' => 'Question', 'name' => $plain($qa[0]),
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $plain($qa[1])],
        ], $items), array_values($faqGroups))),
    ]] : [];

    if ($faqLd !== []) {
        $toc = '<nav aria-label="FAQ topics"><ul>'.implode('', array_map(
            fn ($g) => '<li><a href="#'.trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($g)), '-').'">'.$e($g).'</a> ('.count($faqGroups[$g]).')</li>',
            array_keys($faqGroups),
        )).'</ul></nav>';
        $html = preg_replace('#(<h2 )#', $toc.'$1', $html, 1);
    }

    $ld = ['@context' => 'https://schema.org', '@graph' => array_merge([
        ['@type' => 'TechArticle', '@id' => $url.'#article', 'headline' => $def['h1'], 'description' => $def['description'], 'url' => $url,
            'mainEntityOfPage' => $url, 'inLanguage' => 'en', 'dateModified' => $modified, 'image' => $ogImage,
            'about' => ['@type' => 'SoftwareSourceCode', 'name' => 'Laravel AI Blog', 'codeRepository' => $repo],
            'author' => $org, 'publisher' => $org,
            'isPartOf' => ['@type' => 'WebSite', 'name' => 'Laravel AI Blog', 'url' => $siteUrl]],
        $breadcrumbs([['Home', $siteUrl], ['Docs', $siteUrl.'docs/'], [$def['nav'], $url]]),
    ], $faqLd)];

    $pager = '<nav class="pager" aria-label="More docs">'
        .($prev ? "<a href=\"{$base}docs/{$prev}/\" rel=\"prev\"><small>Previous</small>".$e($docDefs[$prev]['nav']).'</a>' : '<span></span>')
        .($next ? "<a href=\"{$base}docs/{$next}/\" rel=\"next\"><small>Next</small>".$e($docDefs[$next]['nav']).'</a>' : '')
        .'</nav>';

    $page = $head([
        'title' => $def['fullTitle'], 'description' => $def['description'], 'url' => $url,
        'md' => "{$siteUrl}docs/{$slug}.md", 'modified' => $modified, 'ld' => $ld, 'css' => $docsCss,
    ])
        .$siteHeader."\n<main id=\"main\" class=\"docs-main\"><div class=\"wrap\">".$navHtml($slug)
        .'<article class="prose">'.$crumbHtml([['Home', $base], ['Docs', $base.'docs/'], [$def['nav'], '']])
        .preg_replace('#</h1>#', '</h1><p class="updated">Updated <time datetime="'.$modified.'">'.date('j F Y', strtotime($modified)).'</time></p>', $html, 1)
        .$pager
        .'<p class="docfoot"><a href="'.$repo.'/blob/main/'.$def['source'].'">Edit on GitHub</a><a href="'.$base.'docs/'.$slug.'.md">View as Markdown</a></p>'
        ."</article></div></main>\n".$siteFooter."\n</body>\n</html>\n";

    $pages["docs/{$slug}/index.html"] = $page;
}

// Docs home.
$cards = fn (string $group) => '<div class="cards">'.implode('', array_map(
    fn ($s) => '<a href="'.$base.'docs/'.$s.'/"><b>'.$e($docDefs[$s]['nav']).'</b><span>'.$e($docDefs[$s]['description']).'</span></a>',
    array_keys(array_filter($docDefs, fn ($d) => $d['group'] === $group)),
)).'</div>';

$docsTitle = 'Docs: Laravel Blog Package with SEO, AI and MCP';
$docsDescription = 'Documentation for Laravel AI Blog: install, SEO scoring, internal links, AI drafting, the MCP server for Claude and ChatGPT, routes and JSON-LD.';
$pages['docs/index.html'] = $head([
    'title' => $docsTitle, 'description' => $docsDescription, 'url' => $siteUrl.'docs/', 'md' => $siteUrl.'llms.txt', 'css' => $docsCss,
    'ld' => ['@context' => 'https://schema.org', '@graph' => [
        ['@type' => 'CollectionPage', 'name' => $docsTitle, 'description' => $docsDescription, 'url' => $siteUrl.'docs/', 'isPartOf' => ['@type' => 'WebSite', 'name' => 'Laravel AI Blog', 'url' => $siteUrl]],
        $breadcrumbs([['Home', $siteUrl], ['Docs', $siteUrl.'docs/']]),
    ]],
])
    .$siteHeader."\n<main id=\"main\" class=\"docs-main\"><div class=\"wrap\">".$navHtml('')
    .'<article class="prose">'.$crumbHtml([['Home', $base], ['Docs', '']])
    .'<h1>Laravel AI Blog documentation</h1>'
    .'<p>Install the package, score your first post, then add the parts you need: AI drafting, the MCP server for agents, the public blog routes or the command bus. Every page is also available as Markdown, and <a href="'.$base.'llms-full.txt">llms-full.txt</a> has all of it in one file.</p>'
    .'<h2>Guides</h2>'.$cards('Guides')
    .'<h2>Project</h2>'.$cards('Project')
    ."</article></div></main>\n".$siteFooter."\n</body>\n</html>\n";

$pages['404.html'] = $head(['title' => 'Page not found | Laravel AI Blog', 'description' => 'This page does not exist. Go to the Laravel AI Blog home page or the documentation.', 'noindex' => true])
    .$siteHeader."\n<main id=\"main\"><section class=\"band\"><div class=\"wrap\"><p class=\"eyebrow\">404</p>"
    .'<h1 style="font-size:var(--step-3);margin-top:12px">This page does not exist.</h1>'
    .'<p class="lede">It may have moved when the docs were reorganised.</p>'
    .'<div class="install"><a class="btn" href="'.$base.'">Home</a><a class="btn" href="'.$base.'docs/">Documentation</a></div>'
    ."</div></section></main>\n".$siteFooter."\n</body>\n</html>\n";

// ---------------------------------------------------------------------------
// AI layer: llms.txt (https://llmstxt.org), Markdown copies, llms-full.txt
// ---------------------------------------------------------------------------

$docList = fn (string $group) => implode("\n", array_map(
    fn ($s) => "- [{$docDefs[$s]['h1']}]({$siteUrl}docs/{$s}.md): {$docDefs[$s]['description']}",
    array_keys(array_filter($docDefs, fn ($d) => $d['group'] === $group)),
));

$llms = <<<TXT
# Laravel AI Blog

> Laravel AI Blog (`{$package}`) is a free, open-source (MIT) blog package for Laravel 10 to 13 on PHP 8.2 to 8.5. It provides posts, categories and tags, a deterministic on-page SEO score with plain-English fixes, TF-IDF internal link suggestions, stale-post detection, BlogPosting and FAQPage JSON-LD, RSS, AI drafting through the official laravel/ai SDK, and an MCP server (laravel/mcp) so Claude, ChatGPT and Claude Code can draft, score, link and schedule posts.

Install with `composer require {$package}`, then `php artisan vendor:publish --tag=ai-blog-migrations` and `php artisan migrate`. The PHP namespace is `ByteMinus\\AiBlog`. It is headless by default: add the `HasBlogSeo` trait to an existing Eloquent Post model or use the bundled one, and enable `/blog` routes with one config flag. Everything the AI or an agent writes is saved as a draft; the MCP server deliberately has no publish tool. MCP tool names are kebab-case (`draft-from-keyword`, `score-post`). Scoring, links, JSON-LD and routes work without any AI provider or API key.

To answer a specific question, start with the FAQ ({$siteUrl}docs/faq.md): {$faqCount} questions answered with exact commands, config keys and method names, checked against the package source. Every docs page has a Markdown copy at the same path with `.md`.

## Docs

{$docList('Guides')}
- [Full documentation in one file]({$siteUrl}llms-full.txt): every page below concatenated

## Source

- [GitHub repository]({$repo}): code, issues, releases
- [Packagist](https://packagist.org/packages/{$package}): `{$package}`

## Optional

{$docList('Project')}

TXT;

$overview = "# Laravel AI Blog\n\n".$relink($intro)."\n\nInstall: `composer require {$package}`\n\n"
    ."Website: {$siteUrl} · Docs: {$siteUrl}docs/ · Repository: {$repo}\n\n"
    ."## Frequently asked questions\n\n"
    .implode("\n\n", array_map(fn ($f) => "**{$f['name']}** {$f['acceptedAnswer']['text']}", $faqEntities))."\n";

$full = "# Laravel AI Blog: full documentation\n\nSource: {$siteUrl} · Package: {$package} · Repository: {$repo}\n\n"
    .implode("\n\n---\n\n", array_map(fn ($d) => preg_replace('/^#/m', '##', $d['markdown']), array_filter($docDefs, fn ($d) => $d['nav'] !== 'Changelog')))
    ."\n";

$aiAgents = [
    'GPTBot', 'OAI-SearchBot', 'ChatGPT-User',
    'ClaudeBot', 'Claude-SearchBot', 'Claude-User', 'anthropic-ai',
    'PerplexityBot', 'Perplexity-User',
    'Google-Extended', 'GoogleOther', 'Applebot', 'Applebot-Extended',
    'Bingbot', 'DuckAssistBot', 'Amazonbot', 'meta-externalagent', 'cohere-ai', 'MistralAI-User', 'CCBot',
];
$robots = "User-agent: *\nAllow: /\n\n"
    .implode('', array_map(fn ($a) => "User-agent: {$a}\nAllow: /\n\n", $aiAgents))
    ."Sitemap: {$siteUrl}sitemap.xml\n";

$urls = array_merge([['', '1.0', $today], ['docs/', '0.9', $today]], array_map(fn ($s) => ["docs/{$s}/", $docDefs[$s]['group'] === 'Guides' ? '0.8' : '0.5', $gitDate($docDefs[$s]['source'])], $slugs));
$sitemap = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n"
    .implode('', array_map(fn ($u) => "  <url><loc>{$siteUrl}{$u[0]}</loc><lastmod>{$u[2]}</lastmod><changefreq>weekly</changefreq><priority>{$u[1]}</priority></url>\n", $urls))
    ."</urlset>\n";

// ---------------------------------------------------------------------------
// Write
// ---------------------------------------------------------------------------

$files = ['index.html' => $home, 'llms.txt' => $llms, 'llms-full.txt' => $full, 'index.md' => $overview,
    'robots.txt' => $robots, 'sitemap.xml' => $sitemap, '.nojekyll' => ''] + $pages;

foreach ($docDefs as $slug => $def) {
    $files["docs/{$slug}.md"] = $def['markdown']."\n";
}

if (is_dir($out)) {
    exec('rm -rf '.escapeshellarg($out));
}

foreach ($files as $path => $contents) {
    @mkdir(dirname("{$out}/{$path}"), 0755, true);
    file_put_contents("{$out}/{$path}", $contents);
}

foreach (['og.png', 'favicon.svg', 'apple-touch-icon.png'] as $asset) {
    copy(__DIR__."/assets/{$asset}", "{$out}/{$asset}");
}

echo "Built {$out} for {$siteUrl}: ".count($pages).' HTML pages, '.count($faqEntities)." FAQ entries, llms.txt, llms-full.txt\n";
