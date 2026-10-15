<?php

declare(strict_types=1);

namespace ByteMinus\AiBlog\Fetching;

use ByteMinus\AiBlog\Fetching\Exceptions\FetchRefused;
use GuzzleHttp\Psr7\Uri;
use InvalidArgumentException;

/**
 * The checks a URL must pass before anything resolves or connects, run on the
 * first URL and again on every redirect.
 *
 * The URL is parsed with the same parser the HTTP client uses, then rebuilt
 * from its parts, so the address judged here is the address requested.
 * Numeric hosts are only accepted in their canonical form: a host whose last
 * label is a number (`2130706433`, `0177.0.0.1`, `0x7f.1`, `127.1`) is
 * something a browser or libc would read as an IPv4 address, and rather than
 * guess which reading a library will pick, it is refused.
 */
final class UrlGuard
{
    /** Ports a research fetch may use. */
    public const ALLOWED_PORTS = [80, 443, 8080, 8443];

    private const MAX_URL_LENGTH = 2048;

    /**
     * Name suffixes that only mean something inside a private network or a
     * cloud provider's internal DNS, or that never resolve publicly.
     *
     * @var list<string>
     */
    private const BLOCKED_SUFFIXES = [
        'localhost', 'local', 'localdomain', 'internal', 'intranet', 'private',
        'corp', 'home', 'lan', 'home.arpa', 'in-addr.arpa', 'ip6.arpa', 'onion',
        'test', 'invalid', 'example',
    ];

    public function __construct(private readonly IpAddressPolicy $policy) {}

    public function check(string $url): FetchTarget
    {
        $url = trim($url);

        if ($url === '' || strlen($url) > self::MAX_URL_LENGTH || preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            throw FetchRefused::because(FetchRefusal::InvalidUrl, $url);
        }

        try {
            $uri = new Uri($url);
        } catch (InvalidArgumentException) {
            $this->refuseUnparsable($url);
        }

        $scheme = strtolower($uri->getScheme());

        if ($scheme === '') {
            throw FetchRefused::because(FetchRefusal::InvalidUrl, $url);
        }

        if ($scheme !== 'http' && $scheme !== 'https') {
            throw FetchRefused::because(FetchRefusal::UnsupportedScheme, $url);
        }

        if ($uri->getUserInfo() !== '' || str_contains($uri->getAuthority(), '@')) {
            throw FetchRefused::because(FetchRefusal::CredentialsInUrl, $url);
        }

        [$host, $literalIp] = $this->host($uri->getHost(), $url);

        $port = $uri->getPort() ?? ($scheme === 'https' ? 443 : 80);

        if (! in_array($port, self::ALLOWED_PORTS, true)) {
            throw FetchRefused::because(FetchRefusal::DisallowedPort, $url, 'port '.$port);
        }

        if ($literalIp !== null && ! $this->policy->allows($literalIp)) {
            throw FetchRefused::because(FetchRefusal::BlockedAddress, $url);
        }

        $path = $uri->getPath() === '' ? '/' : $uri->getPath();

        if (! str_starts_with($path, '/')) {
            throw FetchRefused::because(FetchRefusal::InvalidUrl, $url);
        }

        $normalised = (new Uri)
            ->withScheme($scheme)
            ->withHost(str_contains($host, ':') ? '['.$host.']' : $host)
            ->withPort($uri->getPort())
            ->withPath($path)
            ->withQuery($uri->getQuery());

        $pathAndQuery = $path.($uri->getQuery() !== '' ? '?'.$uri->getQuery() : '');

        return new FetchTarget((string) $normalised, $scheme, $host, $port, $pathAndQuery, $literalIp);
    }

    /**
     * Older guzzle/psr7 cannot parse some IPv6 literals, such as an IPv4-mapped
     * address in dotted form. The URL is refused either way; PHP's own parser
     * names the reason, so the answer does not depend on the library version.
     */
    private function refuseUnparsable(string $url): never
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower($parts['scheme'] ?? '') : '';
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';

        if ($scheme !== '' && $scheme !== 'http' && $scheme !== 'https') {
            throw FetchRefused::because(FetchRefusal::UnsupportedScheme, $url);
        }

        if ($scheme !== '' && str_starts_with($host, '[')) {
            [, $literalIp] = $this->host($host, $url);

            if ($literalIp !== null && ! $this->policy->allows($literalIp)) {
                throw FetchRefused::because(FetchRefusal::BlockedAddress, $url);
            }
        }

        throw FetchRefused::because(FetchRefusal::InvalidUrl, $url);
    }

    /**
     * The host in lower-case ASCII, and the IP address when the host is one.
     *
     * @return array{0: string, 1: ?string}
     */
    private function host(string $host, string $url): array
    {
        if ($host === '') {
            throw FetchRefused::because(FetchRefusal::InvalidUrl, $url);
        }

        if (str_starts_with($host, '[')) {
            $literal = substr($host, 1, -1);

            if (str_contains($literal, '%')) {
                throw FetchRefused::because(FetchRefusal::InvalidUrl, $url);
            }

            if (! str_ends_with($host, ']')
                || filter_var($literal, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw FetchRefused::because(FetchRefusal::NonCanonicalAddress, $url);
            }

            $canonical = (string) inet_ntop((string) inet_pton($literal));

            return [$canonical, $canonical];
        }

        $host = rtrim(strtolower(rawurldecode($host)), '.');

        if (preg_match('/[^\x21-\x7E]/', $host) === 1) {
            $ascii = function_exists('idn_to_ascii') ? idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46) : false;

            if ($ascii === false) {
                throw FetchRefused::because(FetchRefusal::InvalidUrl, $url);
            }

            $host = strtolower($ascii);
        }

        if (strlen($host) > 253 || preg_match('/^(?:[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?\.)*[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?$/', $host) !== 1) {
            throw FetchRefused::because(FetchRefusal::InvalidUrl, $url);
        }

        $labels = explode('.', $host);
        $lastLabel = $labels[count($labels) - 1];

        if (preg_match('/^(?:0x[0-9a-f]*|[0-9]+)$/', $lastLabel) === 1) {
            if (! $this->isCanonicalIpv4($host)) {
                throw FetchRefused::because(FetchRefusal::NonCanonicalAddress, $url);
            }

            return [$host, $host];
        }

        if (count($labels) < 2) {
            throw FetchRefused::because(FetchRefusal::BlockedHostname, $url);
        }

        foreach (self::BLOCKED_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                throw FetchRefused::because(FetchRefusal::BlockedHostname, $url);
            }
        }

        return [$host, null];
    }

    private function isCanonicalIpv4(string $host): bool
    {
        $octet = '(?:25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])';

        return preg_match('/^'.$octet.'(?:\.'.$octet.'){3}$/', $host) === 1;
    }
}
