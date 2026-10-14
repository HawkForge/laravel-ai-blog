<?php

declare(strict_types=1);

namespace AiBlog\Tests\Unit\Fetching;

use AiBlog\Fetching\Exceptions\FetchFailed;
use AiBlog\Fetching\Exceptions\FetchRefused;
use AiBlog\Fetching\FetchRefusal;
use AiBlog\Fetching\HostResolver;
use AiBlog\Fetching\IpAddressPolicy;
use AiBlog\Fetching\SafeFetcher;
use AiBlog\Fetching\SystemHostResolver;
use AiBlog\Fetching\UrlGuard;
use AiBlog\Tests\TestCase;
use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

class SafeFetcherTest extends TestCase
{
    private const PUBLIC_IP = '93.184.216.34';

    /** @var list<array{url: string, options: array<string, mixed>}> */
    private array $sent = [];

    public function test_the_resolver_contract_resolves_to_the_system_resolver(): void
    {
        $this->assertInstanceOf(SystemHostResolver::class, app(HostResolver::class));
        $this->assertInstanceOf(SafeFetcher::class, app(SafeFetcher::class));
    }

    public function test_it_fetches_a_page_pinned_to_the_checked_address_with_a_clear_user_agent_and_no_cookies(): void
    {
        $this->fakeWeb([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/guide' => Http::response('<html><title>Guide</title></html>', 200, ['Content-Type' => 'text/html; charset=utf-8']),
        ]);

        $document = $this->fetcher()->fetch('https://example.com/guide#top');

        $this->assertSame('https://example.com/guide#top', $document->requestedUrl);
        $this->assertSame('https://example.com/guide', $document->finalUrl);
        $this->assertSame(200, $document->status);
        $this->assertSame('text/html; charset=utf-8', $document->contentType);
        $this->assertSame('text/html', $document->mediaType());
        $this->assertSame('UTF-8', $document->charset());
        $this->assertSame('<html><title>Guide</title></html>', $document->body);
        $this->assertSame(hash('sha256', $document->body), $document->sha256);
        $this->assertSame([], $document->redirects);
        $this->assertNotNull($document->fetchedAt);

        $this->assertSame(['https://example.com/robots.txt', 'https://example.com/guide'], array_column($this->sent, 'url'));

        foreach ($this->sent as $sent) {
            $this->assertSame(['example.com:443:'.self::PUBLIC_IP], $sent['options']['curl'][CURLOPT_RESOLVE]);
            $this->assertTrue($sent['options']['curl'][CURLOPT_FRESH_CONNECT]);
            $this->assertFalse($sent['options']['cookies']);
            $this->assertFalse($sent['options']['allow_redirects']);
            $this->assertSame(['no' => ['*']], $sent['options']['proxy']);
            $this->assertSame(['http', 'https'], $sent['options']['protocols']);
            $this->assertLessThanOrEqual(30, $sent['options']['timeout']);
            $this->assertArrayNotHasKey('stream', $sent['options'], 'Streaming would swap in the stream handler, which cannot pin the address.');
            $cap = str_ends_with($sent['url'], '/robots.txt') ? 512 * 1024 : 2 * 1024 * 1024;
            $this->assertFalse($sent['options']['progress'](0, $cap));
            $this->assertTrue($sent['options']['progress'](0, $cap + 1));
        }

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->header('User-Agent')[0] ?? '', 'AiBlogBot/1.0')
                && $request->header('Cookie') === []
                && $request->header('Referer') === [];
        });
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function blockedResolutions(): array
    {
        return [
            'private' => [['10.0.0.7']],
            'loopback' => [['127.0.0.1']],
            'link-local metadata' => [['169.254.169.254']],
            'cgnat' => [['100.64.3.4']],
            'multicast' => [['239.1.1.1']],
            'reserved' => [['250.1.1.1']],
            'ipv6 loopback' => [['::1']],
            'ipv4-mapped ipv6 loopback' => [['::ffff:127.0.0.1']],
            'ipv6 unique local' => [['fd12:3456::1']],
            'ipv6 link-local' => [['fe80::1']],
            'one bad address among good ones' => [[self::PUBLIC_IP, '192.168.0.10']],
        ];
    }

    /**
     * @param  list<string>  $addresses
     */
    #[DataProvider('blockedResolutions')]
    public function test_it_refuses_hosts_that_resolve_to_blocked_addresses_before_connecting(array $addresses): void
    {
        $this->fakeWeb([]);

        $this->assertRefused(FetchRefusal::BlockedAddress, fn () => $this->fetcher(new FakeHostResolver(['evil.example.com' => $addresses]))->fetch('https://evil.example.com/'));

        Http::assertNothingSent();
    }

    public function test_it_refuses_a_host_that_does_not_resolve(): void
    {
        $this->fakeWeb([]);

        $this->assertRefused(FetchRefusal::UnresolvableHost, fn () => $this->fetcher(new FakeHostResolver)->fetch('https://nowhere.example.net/'));

        Http::assertNothingSent();
    }

    public function test_it_refuses_a_numeric_trick_host_without_resolving_it(): void
    {
        $this->fakeWeb([]);
        $resolver = new FakeHostResolver;

        $this->assertRefused(FetchRefusal::NonCanonicalAddress, fn () => $this->fetcher($resolver)->fetch('http://2130706433/'));
        $this->assertRefused(FetchRefusal::BlockedAddress, fn () => $this->fetcher($resolver)->fetch('http://[::ffff:169.254.169.254]/'));

        $this->assertSame([], $resolver->lookups);
        Http::assertNothingSent();
    }

    public function test_it_follows_redirects_checking_every_hop(): void
    {
        $resolver = new FakeHostResolver([
            'example.com' => [self::PUBLIC_IP],
            'www.example.org' => ['93.184.216.35'],
        ]);

        $this->fakeWeb([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://www.example.org/robots.txt' => Http::response('', 404),
            'https://example.com/old' => Http::response('', 301, ['Location' => '/newer']),
            'https://example.com/newer' => Http::response('', 302, ['Location' => 'https://www.example.org/final?x=1#frag']),
            'https://www.example.org/final?x=1' => Http::response('done', 200, ['Content-Type' => 'text/plain']),
        ]);

        $document = $this->fetcher($resolver)->fetch('https://example.com/old');

        $this->assertSame('https://www.example.org/final?x=1', $document->finalUrl);
        $this->assertSame(['https://example.com/old', 'https://example.com/newer'], $document->redirects);
        $this->assertSame('done', $document->body);

        $final = collect($this->sent)->firstWhere('url', 'https://www.example.org/final?x=1');
        $this->assertSame(['www.example.org:443:93.184.216.35'], $final['options']['curl'][CURLOPT_RESOLVE]);
    }

    public function test_it_refuses_a_redirect_to_a_private_ip(): void
    {
        $this->fakeWeb([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/go' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
        ]);

        $this->assertRefused(FetchRefusal::BlockedAddress, fn () => $this->fetcher()->fetch('https://example.com/go'));

        $this->assertNotContains('http://169.254.169.254/latest/meta-data/', array_column($this->sent, 'url'));
    }

    public function test_it_refuses_a_redirect_to_a_host_that_resolves_privately(): void
    {
        $resolver = new FakeHostResolver([
            'example.com' => [self::PUBLIC_IP],
            'internal.example.net' => ['10.20.30.40'],
        ]);

        $this->fakeWeb([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/go' => Http::response('', 307, ['Location' => 'https://internal.example.net/admin']),
        ]);

        $this->assertRefused(FetchRefusal::BlockedAddress, fn () => $this->fetcher($resolver)->fetch('https://example.com/go'));

        $this->assertSame(['https://example.com/robots.txt', 'https://example.com/go'], array_column($this->sent, 'url'));
    }

    public function test_it_refuses_a_redirect_to_another_scheme_or_without_a_location(): void
    {
        $this->fakeWeb([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/file' => Http::response('', 302, ['Location' => 'file:///etc/passwd']),
            'https://example.com/empty' => Http::response('', 302),
        ]);

        $this->assertRefused(FetchRefusal::UnsupportedScheme, fn () => $this->fetcher()->fetch('https://example.com/file'));
        $this->assertRefused(FetchRefusal::InvalidRedirect, fn () => $this->fetcher()->fetch('https://example.com/empty'));
    }

    public function test_it_stops_after_five_redirects(): void
    {
        $routes = ['https://example.com/robots.txt' => Http::response('', 404)];

        for ($hop = 0; $hop <= 6; $hop++) {
            $routes['https://example.com/hop'.$hop] = Http::response('', 302, ['Location' => '/hop'.($hop + 1)]);
        }

        $this->fakeWeb($routes);

        $this->assertRefused(FetchRefusal::TooManyRedirects, fn () => $this->fetcher()->fetch('https://example.com/hop0'));

        $pages = array_filter(array_column($this->sent, 'url'), fn (string $url): bool => str_contains($url, '/hop'));
        $this->assertCount(SafeFetcher::MAX_REDIRECTS + 1, $pages);
    }

    public function test_dns_rebinding_cannot_move_the_connection_after_the_check(): void
    {
        $resolver = new FakeHostResolver(
            ['rebind.example.com' => [self::PUBLIC_IP]],
            ['rebind.example.com' => ['127.0.0.1']],
        );

        $this->fakeWeb([
            'https://rebind.example.com/robots.txt' => Http::response("User-agent: *\nAllow: /", 200, ['Content-Type' => 'text/plain']),
            'https://rebind.example.com/page' => Http::response('', 302, ['Location' => '/page2']),
            'https://rebind.example.com/page2' => Http::response('ok', 200, ['Content-Type' => 'text/plain']),
        ]);

        $this->fetcher($resolver)->fetch('https://rebind.example.com/page');

        $this->assertSame(1, $resolver->lookups['rebind.example.com']);
        $this->assertCount(3, $this->sent);

        foreach ($this->sent as $sent) {
            $this->assertSame(['rebind.example.com:443:'.self::PUBLIC_IP], $sent['options']['curl'][CURLOPT_RESOLVE]);
        }
    }

    public function test_a_new_fetch_resolves_again_and_refuses_a_rebound_address(): void
    {
        $resolver = new FakeHostResolver(
            ['rebind.example.com' => [self::PUBLIC_IP]],
            ['rebind.example.com' => ['169.254.169.254']],
        );

        $this->fakeWeb([
            'https://rebind.example.com/robots.txt' => Http::response('', 404),
            'https://rebind.example.com/page' => Http::response('ok', 200, ['Content-Type' => 'text/plain']),
        ]);

        $this->fetcher($resolver)->fetch('https://rebind.example.com/page');

        $this->assertRefused(FetchRefusal::BlockedAddress, fn () => $this->fetcher($resolver)->fetch('https://rebind.example.com/page'));
    }

    public function test_it_pins_ipv6_addresses_in_brackets_and_prefers_ipv4(): void
    {
        $this->fakeWeb([
            'https://six.example.com/robots.txt' => Http::response('', 404),
            'https://six.example.com/' => Http::response('ok', 200, ['Content-Type' => 'text/plain']),
            'https://dual.example.com/robots.txt' => Http::response('', 404),
            'https://dual.example.com/' => Http::response('ok', 200, ['Content-Type' => 'text/plain']),
        ]);

        $fetcher = $this->fetcher(new FakeHostResolver([
            'six.example.com' => ['2606:2800:220:1::1'],
            'dual.example.com' => ['2606:2800:220:1::1', self::PUBLIC_IP],
        ]));

        $fetcher->fetch('https://six.example.com/');
        $fetcher->fetch('https://dual.example.com/');

        $this->assertSame(['six.example.com:443:[2606:2800:220:1::1]'], $this->sent[1]['options']['curl'][CURLOPT_RESOLVE]);
        $this->assertSame(['dual.example.com:443:'.self::PUBLIC_IP], $this->sent[3]['options']['curl'][CURLOPT_RESOLVE]);
    }

    public function test_it_refuses_a_body_over_the_cap_while_reading(): void
    {
        $this->fakeWeb([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/big' => Http::response(str_repeat('a', 1001), 200, ['Content-Type' => 'text/plain']),
            'https://example.com/fits' => Http::response(str_repeat('a', 1000), 200, ['Content-Type' => 'text/plain']),
        ]);

        $this->assertRefused(FetchRefusal::TooLarge, fn () => $this->fetcher(maxBytes: 1000)->fetch('https://example.com/big'));
        $this->assertSame(1000, strlen($this->fetcher(maxBytes: 1000)->fetch('https://example.com/fits')->body));
    }

    public function test_it_refuses_a_declared_length_over_the_cap(): void
    {
        $this->fakeWeb([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/big' => Http::response('small', 200, ['Content-Type' => 'text/plain', 'Content-Length' => '5000000']),
        ]);

        $this->assertRefused(FetchRefusal::TooLarge, fn () => $this->fetcher()->fetch('https://example.com/big'));
    }

    public function test_it_decodes_gzip_and_refuses_a_compression_bomb(): void
    {
        $this->fakeWeb([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/small' => Http::response((string) gzencode('hello there'), 200, ['Content-Type' => 'text/plain', 'Content-Encoding' => 'gzip']),
            'https://example.com/bomb' => Http::response((string) gzencode(str_repeat('a', 50_000)), 200, ['Content-Type' => 'text/plain', 'Content-Encoding' => 'gzip']),
            'https://example.com/brotli' => Http::response('xx', 200, ['Content-Type' => 'text/plain', 'Content-Encoding' => 'br']),
        ]);

        $fetcher = $this->fetcher(maxBytes: 1000);

        $document = $fetcher->fetch('https://example.com/small');
        $this->assertSame('hello there', $document->body);
        $this->assertSame(hash('sha256', 'hello there'), $document->sha256);

        $this->assertRefused(FetchRefusal::TooLarge, fn () => $fetcher->fetch('https://example.com/bomb'));
        $this->assertRefused(FetchRefusal::UnsupportedEncoding, fn () => $fetcher->fetch('https://example.com/brotli'));
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function refusedContentTypes(): array
    {
        return [
            'image' => ['image/png'],
            'binary' => ['application/octet-stream'],
            'pdf' => ['application/pdf'],
            'svg' => ['image/svg+xml'],
            'javascript' => ['application/javascript'],
            'missing' => [null],
        ];
    }

    #[DataProvider('refusedContentTypes')]
    public function test_it_refuses_content_types_it_does_not_keep(?string $contentType): void
    {
        $this->fakeWeb([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/thing' => Http::response('xx', 200, $contentType === null ? [] : ['Content-Type' => $contentType]),
        ]);

        $this->assertRefused(FetchRefusal::UnsupportedContentType, fn () => $this->fetcher()->fetch('https://example.com/thing'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function keptContentTypes(): array
    {
        return [
            'html' => ['text/html; charset=ISO-8859-1'],
            'xhtml' => ['application/xhtml+xml'],
            'rss' => ['application/rss+xml; charset=utf-8'],
            'atom' => ['application/atom+xml'],
            'xml' => ['text/xml'],
            'json' => ['application/json'],
            'json feed' => ['application/feed+json'],
            'plain' => ['TEXT/PLAIN'],
        ];
    }

    #[DataProvider('keptContentTypes')]
    public function test_it_keeps_html_xml_feeds_json_and_text(string $contentType): void
    {
        $this->fakeWeb([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/thing' => Http::response('xx', 200, ['Content-Type' => $contentType]),
        ]);

        $this->assertSame($contentType, $this->fetcher()->fetch('https://example.com/thing')->contentType);
    }

    public function test_it_respects_robots_txt_for_its_own_token_and_caches_the_rules(): void
    {
        $robots = implode("\n", [
            'User-agent: *',
            'Disallow: /',
            '',
            'User-agent: AiBlogBot',
            'Disallow: /private/',
            'Allow: /private/press$',
        ]);

        $this->fakeWeb([
            'https://example.com/robots.txt' => Http::response($robots, 200, ['Content-Type' => 'text/plain']),
            'https://example.com/guides/one' => Http::response('one', 200, ['Content-Type' => 'text/plain']),
            'https://example.com/private/press' => Http::response('press', 200, ['Content-Type' => 'text/plain']),
        ]);

        $fetcher = $this->fetcher();

        $this->assertSame('one', $fetcher->fetch('https://example.com/guides/one')->body);
        $this->assertSame('press', $fetcher->fetch('https://example.com/private/press')->body);
        $this->assertRefused(FetchRefusal::DisallowedByRobots, fn () => $fetcher->fetch('https://example.com/private/plans'));

        $robotsRequests = array_filter(array_column($this->sent, 'url'), fn (string $url): bool => str_ends_with($url, '/robots.txt'));
        $this->assertCount(1, $robotsRequests);
        $this->assertNotContains('https://example.com/private/plans', array_column($this->sent, 'url'));
    }

    public function test_robots_txt_that_cannot_be_read_refuses_the_site_for_now_without_caching(): void
    {
        $this->fakeWeb([
            'https://example.com/robots.txt' => Http::response('', 503),
            'https://example.com/page' => Http::response('page', 200, ['Content-Type' => 'text/plain']),
        ]);

        $fetcher = $this->fetcher();

        $refused = $this->assertRefused(FetchRefusal::RobotsUnavailable, fn () => $fetcher->fetch('https://example.com/page'));
        $this->assertTrue($refused->retryable);

        $this->assertRefused(FetchRefusal::RobotsUnavailable, fn () => $fetcher->fetch('https://example.com/page'));
        $this->assertCount(2, $this->sent);
    }

    public function test_robots_txt_is_checked_for_the_host_a_redirect_lands_on(): void
    {
        $resolver = new FakeHostResolver([
            'example.com' => [self::PUBLIC_IP],
            'other.example.org' => ['93.184.216.35'],
        ]);

        $this->fakeWeb([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://other.example.org/robots.txt' => Http::response("User-agent: *\nDisallow: /", 200, ['Content-Type' => 'text/plain']),
            'https://example.com/go' => Http::response('', 302, ['Location' => 'https://other.example.org/page']),
        ]);

        $this->assertRefused(FetchRefusal::DisallowedByRobots, fn () => $this->fetcher($resolver)->fetch('https://example.com/go'));
        $this->assertNotContains('https://other.example.org/page', array_column($this->sent, 'url'));
    }

    public function test_error_statuses_fail_with_the_right_retry_advice(): void
    {
        $this->fakeWeb([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/gone' => Http::response('', 404),
            'https://example.com/busy' => Http::response('', 503),
        ]);

        try {
            $this->fetcher()->fetch('https://example.com/gone');
            $this->fail('Expected a 404 to fail.');
        } catch (FetchFailed $failed) {
            $this->assertSame(404, $failed->status);
            $this->assertFalse($failed->retryable);
        }

        try {
            $this->fetcher()->fetch('https://example.com/busy');
            $this->fail('Expected a 503 to fail.');
        } catch (FetchFailed $failed) {
            $this->assertSame(503, $failed->status);
            $this->assertTrue($failed->retryable);
        }
    }

    public function test_a_connection_failure_is_a_retryable_failure(): void
    {
        $this->fakeWeb([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/page' => Http::failedConnection(),
        ]);

        try {
            $this->fetcher()->fetch('https://example.com/page');
            $this->fail('Expected the fetch to fail.');
        } catch (FetchFailed $failed) {
            $this->assertTrue($failed->retryable);
            $this->assertNull($failed->status);
        }

        Cache::flush();
        $this->fakeWeb(['https://example.com/robots.txt' => Http::failedConnection()]);

        $refused = $this->assertRefused(FetchRefusal::RobotsUnavailable, fn () => $this->fetcher()->fetch('https://example.com/page'));
        $this->assertTrue($refused->retryable);
    }

    public function test_it_refuses_once_the_time_budget_is_spent(): void
    {
        $this->fakeWeb([]);

        $this->assertRefused(FetchRefusal::OutOfTime, fn () => $this->fetcher(totalSeconds: 0.0)->fetch('https://example.com/page'));

        Http::assertNothingSent();
    }

    public function test_it_refuses_to_run_inside_a_web_request(): void
    {
        $this->fakeWeb([]);

        $console = new ReflectionProperty($this->app, 'isRunningInConsole');
        $console->setValue($this->app, false);

        try {
            $this->assertRefused(FetchRefusal::WebRequest, fn () => $this->fetcher()->fetch('https://example.com/page'));
        } finally {
            $console->setValue($this->app, true);
        }

        Http::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $routes
     */
    private function fakeWeb(array $routes): void
    {
        $this->sent = [];

        Http::preventStrayRequests();
        Http::fake(function (Request $request, array $options) use ($routes) {
            $this->sent[] = ['url' => $request->url(), 'options' => $options];

            return $routes[$request->url()] ?? Http::response('not found', 404);
        });
    }

    private function fetcher(?HostResolver $resolver = null, int $maxBytes = 2 * 1024 * 1024, float $totalSeconds = 30.0): SafeFetcher
    {
        $policy = new IpAddressPolicy;

        return new SafeFetcher(
            $resolver ?? new FakeHostResolver(['example.com' => [self::PUBLIC_IP]]),
            $policy,
            new UrlGuard($policy),
            maxBytes: $maxBytes,
            totalSeconds: $totalSeconds,
        );
    }

    private function assertRefused(FetchRefusal $reason, Closure $fetch): FetchRefused
    {
        try {
            $fetch();
        } catch (FetchRefused $refused) {
            $this->assertSame($reason, $refused->reason, $refused->getMessage());

            return $refused;
        }

        $this->fail('Expected the fetch to be refused with '.$reason->value.'.');
    }
}
