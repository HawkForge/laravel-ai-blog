<?php

declare(strict_types=1);

namespace AiBlog\Tests\Unit\Fetching;

use AiBlog\Fetching\Exceptions\FetchRefused;
use AiBlog\Fetching\FetchRefusal;
use AiBlog\Fetching\IpAddressPolicy;
use AiBlog\Fetching\UrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use AiBlog\Tests\TestCase;

class UrlGuardTest extends TestCase
{
    /**
     * @return array<string, array{string, FetchRefusal}>
     */
    public static function refusedUrls(): array
    {
        return [
            'empty' => ['', FetchRefusal::InvalidUrl],
            'relative' => ['/just/a/path', FetchRefusal::InvalidUrl],
            'file scheme' => ['file:///etc/passwd', FetchRefusal::UnsupportedScheme],
            'gopher scheme' => ['gopher://example.com/', FetchRefusal::UnsupportedScheme],
            'ftp scheme' => ['ftp://example.com/file', FetchRefusal::UnsupportedScheme],
            'data scheme' => ['data:text/html,hello', FetchRefusal::UnsupportedScheme],
            'credentials' => ['https://user:pass@example.com/', FetchRefusal::CredentialsInUrl],
            'at sign confusion' => ['https://example.com@127.0.0.1/', FetchRefusal::CredentialsInUrl],
            'backslash confusion' => ['https://127.0.0.1\\@example.com/', FetchRefusal::InvalidUrl],
            'whitespace' => ['https://exa mple.com/', FetchRefusal::InvalidUrl],
            'newline' => ["https://example.com/\nHost: x", FetchRefusal::InvalidUrl],
            'odd port' => ['https://example.com:22/', FetchRefusal::DisallowedPort],
            'redis port' => ['http://example.com:6379/', FetchRefusal::DisallowedPort],
            'localhost' => ['http://localhost/', FetchRefusal::BlockedHostname],
            'localhost subdomain' => ['http://api.localhost/', FetchRefusal::BlockedHostname],
            'single label' => ['http://intranet/', FetchRefusal::BlockedHostname],
            'gcp metadata name' => ['http://metadata.google.internal/computeMetadata/v1/', FetchRefusal::BlockedHostname],
            'mdns' => ['http://printer.local/', FetchRefusal::BlockedHostname],
            'home arpa' => ['http://router.home.arpa/', FetchRefusal::BlockedHostname],
            'loopback literal' => ['http://127.0.0.1/', FetchRefusal::BlockedAddress],
            'metadata literal' => ['http://169.254.169.254/latest/meta-data/', FetchRefusal::BlockedAddress],
            'private literal' => ['http://10.0.0.5:8080/', FetchRefusal::BlockedAddress],
            'decimal ip' => ['http://2130706433/', FetchRefusal::NonCanonicalAddress],
            'octal ip' => ['http://0177.0.0.1/', FetchRefusal::NonCanonicalAddress],
            'hex ip' => ['http://0x7f.0.0.1/', FetchRefusal::NonCanonicalAddress],
            'hex whole ip' => ['http://0x7f000001/', FetchRefusal::NonCanonicalAddress],
            'short ip' => ['http://127.1/', FetchRefusal::NonCanonicalAddress],
            'leading zero octet' => ['http://010.0.0.1/', FetchRefusal::NonCanonicalAddress],
            'percent-encoded ip' => ['http://%31%32%37.0.0.1/', FetchRefusal::BlockedAddress],
            'ipv6 loopback' => ['http://[::1]/', FetchRefusal::BlockedAddress],
            'ipv4-mapped ipv6 loopback' => ['http://[::ffff:127.0.0.1]/', FetchRefusal::BlockedAddress],
            'ipv4-mapped ipv6 metadata hex' => ['http://[::ffff:a9fe:a9fe]/', FetchRefusal::BlockedAddress],
            'ipv6 zone id' => ['http://[fe80::1%25eth0]/', FetchRefusal::InvalidUrl],
            'ipv6 unique local' => ['http://[fd00:ec2::254]/', FetchRefusal::BlockedAddress],
        ];
    }

    #[DataProvider('refusedUrls')]
    public function test_it_refuses_urls_that_could_reach_inside(string $url, FetchRefusal $reason): void
    {
        try {
            $this->guard()->check($url);
            $this->fail('Expected '.$url.' to be refused.');
        } catch (FetchRefused $refused) {
            $this->assertSame($reason, $refused->reason, $refused->getMessage());
            $this->assertFalse($refused->retryable);
        }
    }

    public function test_it_normalises_an_acceptable_url(): void
    {
        $target = $this->guard()->check('HTTPS://Example.COM./guides/a%20b?x=1#section');

        $this->assertSame('https://example.com/guides/a%20b?x=1', $target->url);
        $this->assertSame('example.com', $target->host);
        $this->assertSame(443, $target->port);
        $this->assertSame('/guides/a%20b?x=1', $target->pathAndQuery);
        $this->assertNull($target->literalIp);
        $this->assertSame('https://example.com', $target->origin());
    }

    public function test_it_accepts_public_ip_literals_and_the_listed_ports(): void
    {
        $ipv4 = $this->guard()->check('http://93.184.216.34:8080');
        $this->assertSame('93.184.216.34', $ipv4->literalIp);
        $this->assertSame('http://93.184.216.34:8080/', $ipv4->url);
        $this->assertSame('http://93.184.216.34:8080', $ipv4->origin());

        $ipv6 = $this->guard()->check('https://[2606:2800:0220:0001::1]/feed');
        $this->assertSame('2606:2800:220:1::1', $ipv6->literalIp);
        $this->assertSame('https://[2606:2800:220:1::1]/feed', $ipv6->url);
    }

    public function test_it_converts_international_names_to_ascii(): void
    {
        $this->assertSame('xn--bcher-kva.example.org', $this->guard()->check('https://bücher.example.org/')->host);
    }

    private function guard(): UrlGuard
    {
        return new UrlGuard(new IpAddressPolicy);
    }
}
