<?php

/*
 * Diagrams for the docs pages, keyed by doc slug.
 *
 * `before` is a string in the rendered page; the figure goes right before it.
 * `alt` is the claim the diagram makes. It is the SVG's aria-label and is also
 * written into the Markdown copy, so AI readers get the same information.
 */

$arrow = fn (string $id) => '<defs>'
    .'<marker id="'.$id.'" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path class="ah" d="M0 0L10 5L0 10z"/></marker>'
    .'<marker id="'.$id.'x" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path class="ahx" d="M0 0L10 5L0 10z"/></marker>'
    .'</defs>';

return [
    'mcp-server' => [
        'before' => '<p>Tools:',
        'alt' => 'An agent such as Claude Code, Claude.ai or ChatGPT calls the MCP endpoint with a token. Your middleware rejects unauthenticated calls before any tool runs. The MCP server writes drafts and schedules; it has no publish tool, so only you or the scheduler publish.',
        'caption' => 'Every agent call crosses your middleware first. Inside, the tools can write and schedule drafts, but nothing an agent calls can publish.',
        'svg' => <<<SVG
<svg viewBox="0 0 770 230" role="img" aria-label="%alt%">
{$arrow('mc-a')}
<rect class="b" x="20" y="70" width="140" height="60" rx="8"/>
<text x="90" y="96" text-anchor="middle" class="t-b">AI agent</text>
<text x="90" y="114" text-anchor="middle" class="t-m">Claude, ChatGPT</text>
<rect class="bd" x="200" y="20" width="550" height="196" rx="12"/>
<text x="216" y="42" class="t-m">your Laravel app</text>
<rect class="b" x="230" y="70" width="130" height="60" rx="8"/>
<text x="295" y="96" text-anchor="middle" class="t-b">Middleware</text>
<text x="295" y="114" text-anchor="middle" class="t-m">auth:sanctum, OAuth</text>
<rect class="b" x="420" y="70" width="130" height="60" rx="8"/>
<text x="485" y="96" text-anchor="middle" class="t-b">MCP server</text>
<text x="485" y="114" text-anchor="middle" class="t-m">8 tools</text>
<rect class="b" x="610" y="70" width="120" height="60" rx="8"/>
<text x="670" y="96" text-anchor="middle" class="t-b">Posts</text>
<text x="670" y="114" text-anchor="middle" class="t-m">draft, scheduled</text>
<line class="e" x1="160" y1="100" x2="226" y2="100" marker-end="url(#mc-a)"/>
<text x="180" y="88" text-anchor="middle" class="t-m">token</text>
<line class="e" x1="360" y1="100" x2="416" y2="100" marker-end="url(#mc-a)"/>
<text x="390" y="88" text-anchor="middle" class="t-m">allowed</text>
<line class="e" x1="550" y1="100" x2="606" y2="100" marker-end="url(#mc-a)"/>
<text x="580" y="88" text-anchor="middle" class="t-m">writes</text>
<line class="x" x1="295" y1="130" x2="295" y2="170" marker-end="url(#mc-ax)"/>
<text x="295" y="192" text-anchor="middle" class="t-k">rejected, no tool runs</text>
<text x="485" y="156" text-anchor="middle" class="t-k">no publish tool</text>
<text x="670" y="156" text-anchor="middle" class="t-m">published only by</text>
<text x="670" y="172" text-anchor="middle" class="t-m">you or the scheduler</text>
</svg>
SVG,
    ],

    'safe-fetching' => [
        'before' => '<p>On the first URL',
        'alt' => 'SafeFetcher checks the URL, resolves the host and requires every address to be public, connects pinned to the checked address, checks robots.txt and reads a size- and time-capped body. A failed step refuses the fetch and returns nothing. A redirect goes back to the first check, at most five times. What it returns goes through TextExtractor and UntrustedText before reaching a model.',
        'caption' => 'A fetch passes five checks in order, and a redirect starts again from the first one. Any failed check stops the fetch with nothing returned.',
        'svg' => <<<SVG
<svg viewBox="0 0 940 260" style="min-width:860px" role="img" aria-label="%alt%">
{$arrow('sf-a')}
<path class="e" d="M704 80V44H80V76" marker-end="url(#sf-a)"/>
<text x="392" y="36" text-anchor="middle" class="t-m">redirect: check again, at most 5 times</text>
<rect class="b" x="20" y="80" width="120" height="56" rx="8"/>
<text x="80" y="104" text-anchor="middle" class="t-b">Check URL</text>
<text x="80" y="122" text-anchor="middle" class="t-m">scheme, port, host</text>
<rect class="b" x="176" y="80" width="120" height="56" rx="8"/>
<text x="236" y="104" text-anchor="middle" class="t-b">Resolve DNS</text>
<text x="236" y="122" text-anchor="middle" class="t-m">all addresses public</text>
<rect class="b" x="332" y="80" width="120" height="56" rx="8"/>
<text x="392" y="104" text-anchor="middle" class="t-b">Connect</text>
<text x="392" y="122" text-anchor="middle" class="t-m">pinned to that IP</text>
<rect class="b" x="488" y="80" width="120" height="56" rx="8"/>
<text x="548" y="104" text-anchor="middle" class="t-b">robots.txt</text>
<text x="548" y="122" text-anchor="middle" class="t-m">must allow</text>
<rect class="b" x="644" y="80" width="120" height="56" rx="8"/>
<text x="704" y="104" text-anchor="middle" class="t-b">Read body</text>
<text x="704" y="122" text-anchor="middle" class="t-m">size and time caps</text>
<rect class="okb" x="800" y="80" width="120" height="56" rx="8"/>
<text x="860" y="104" text-anchor="middle" class="t-b">Untrusted text</text>
<text x="860" y="122" text-anchor="middle" class="t-m">for the model</text>
<line class="e" x1="140" y1="108" x2="172" y2="108" marker-end="url(#sf-a)"/>
<line class="e" x1="296" y1="108" x2="328" y2="108" marker-end="url(#sf-a)"/>
<line class="e" x1="452" y1="108" x2="484" y2="108" marker-end="url(#sf-a)"/>
<line class="e" x1="608" y1="108" x2="640" y2="108" marker-end="url(#sf-a)"/>
<line class="e" x1="764" y1="108" x2="796" y2="108" marker-end="url(#sf-a)"/>
<line class="x" x1="80" y1="136" x2="80" y2="196" marker-end="url(#sf-ax)"/>
<line class="x" x1="236" y1="136" x2="236" y2="196" marker-end="url(#sf-ax)"/>
<line class="x" x1="392" y1="136" x2="392" y2="196" marker-end="url(#sf-ax)"/>
<line class="x" x1="548" y1="136" x2="548" y2="196" marker-end="url(#sf-ax)"/>
<line class="x" x1="704" y1="136" x2="704" y2="196" marker-end="url(#sf-ax)"/>
<rect class="bk" x="20" y="200" width="744" height="36" rx="8"/>
<text x="392" y="223" text-anchor="middle" class="t-k">refused or failed: nothing is returned</text>
</svg>
SVG,
    ],

    'command-bus' => [
        'before' => '<pre><code class="language-php">use ByteMinus',
        'alt' => 'A command request carries an idempotency key. If the key was seen before, the stored result is replayed and nothing runs again. Otherwise the actor must hold the ability, the params must pass the rules, and then the handler runs. Refusals, invalid params and results are all written to the audit trail.',
        'caption' => 'A retry with the same key gets the stored result back. A new request has to pass the ability check and validation before the handler runs, and every outcome is audited.',
        'svg' => <<<SVG
<svg viewBox="0 0 820 250" style="min-width:720px" role="img" aria-label="%alt%">
{$arrow('cb-a')}
<rect class="b" x="20" y="110" width="140" height="52" rx="8"/>
<text x="90" y="133" text-anchor="middle" class="t-b">Request</text>
<text x="90" y="151" text-anchor="middle" class="t-m">key, params</text>
<rect class="b" x="200" y="110" width="110" height="52" rx="8"/>
<text x="255" y="141" text-anchor="middle" class="t-b">Key seen?</text>
<rect class="b" x="350" y="110" width="110" height="52" rx="8"/>
<text x="405" y="133" text-anchor="middle" class="t-b">Ability</text>
<text x="405" y="151" text-anchor="middle" class="t-m">Gate check</text>
<rect class="b" x="500" y="110" width="110" height="52" rx="8"/>
<text x="555" y="133" text-anchor="middle" class="t-b">Validate</text>
<text x="555" y="151" text-anchor="middle" class="t-m">rules()</text>
<rect class="okb" x="650" y="110" width="150" height="52" rx="8"/>
<text x="725" y="133" text-anchor="middle" class="t-b">Handler</text>
<text x="725" y="151" text-anchor="middle" class="t-m">handle()</text>
<rect class="okb" x="200" y="20" width="260" height="44" rx="8"/>
<text x="330" y="40" text-anchor="middle" class="t-b">Replay stored result</text>
<text x="330" y="56" text-anchor="middle" class="t-m">replayed = true, nothing runs</text>
<line class="e" x1="255" y1="110" x2="255" y2="68" marker-end="url(#cb-a)"/>
<text x="263" y="94" class="t-m">yes</text>
<line class="e" x1="160" y1="136" x2="196" y2="136" marker-end="url(#cb-a)"/>
<line class="e" x1="310" y1="136" x2="346" y2="136" marker-end="url(#cb-a)"/>
<text x="328" y="128" text-anchor="middle" class="t-m">no</text>
<line class="e" x1="460" y1="136" x2="496" y2="136" marker-end="url(#cb-a)"/>
<line class="e" x1="610" y1="136" x2="646" y2="136" marker-end="url(#cb-a)"/>
<rect class="b" x="200" y="206" width="600" height="34" rx="8"/>
<text x="500" y="228" text-anchor="middle" class="t-b">Audit trail: every outcome recorded</text>
<line class="x" x1="405" y1="162" x2="405" y2="202" marker-end="url(#cb-ax)"/>
<text x="413" y="186" class="t-k">refused</text>
<line class="x" x1="555" y1="162" x2="555" y2="202" marker-end="url(#cb-ax)"/>
<text x="563" y="186" class="t-k">invalid</text>
<line class="e" x1="725" y1="162" x2="725" y2="202" marker-end="url(#cb-a)"/>
<text x="733" y="186" class="t-m">result</text>
</svg>
SVG,
    ],

    'seo-routes' => [
        'before' => '<h3 id="what-the-pages-send-to-search-engines">',
        'alt' => 'Each post page sends search engines a canonical URL, a robots tag and BlogPosting JSON-LD; sends social networks og: and twitter: cards with an absolute image URL; and links feed readers to the RSS 2.0 feed.',
        'caption' => 'One post page, three kinds of reader. Each one finds the tags it looks for, and tags with nothing to say are left out.',
        'svg' => <<<SVG
<svg viewBox="0 0 760 240" role="img" aria-label="%alt%">
{$arrow('sr-a')}
<rect class="b" x="20" y="90" width="200" height="70" rx="8"/>
<text x="120" y="120" text-anchor="middle" class="t-b">Post page</text>
<text x="120" y="140" text-anchor="middle" class="t-m">/blog/{slug}</text>
<rect class="b" x="520" y="30" width="220" height="48" rx="8"/>
<text x="630" y="59" text-anchor="middle" class="t-b">Search engines</text>
<rect class="b" x="520" y="100" width="220" height="48" rx="8"/>
<text x="630" y="129" text-anchor="middle" class="t-b">Social previews</text>
<rect class="b" x="520" y="170" width="220" height="48" rx="8"/>
<text x="630" y="199" text-anchor="middle" class="t-b">Feed readers</text>
<line class="e" x1="220" y1="110" x2="516" y2="54" marker-end="url(#sr-a)"/>
<text x="368" y="58" text-anchor="middle" class="t-m">canonical · robots · JSON-LD</text>
<line class="e" x1="220" y1="125" x2="516" y2="124" marker-end="url(#sr-a)"/>
<text x="368" y="116" text-anchor="middle" class="t-m">og: · twitter: · absolute image</text>
<line class="e" x1="220" y1="140" x2="516" y2="194" marker-end="url(#sr-a)"/>
<text x="330" y="200" text-anchor="middle" class="t-m">RSS 2.0 link</text>
</svg>
SVG,
    ],
];
