<?php

declare(strict_types=1);

namespace ByteMinus\AiBlog\Fetching;

use ByteMinus\AiBlog\Fetching\Exceptions\FetchFailed;
use ByteMinus\AiBlog\Fetching\Exceptions\FetchRefused;
use Carbon\CarbonImmutable;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;

/**
 * Fetches a public web page, feed or JSON document for research, and nothing
 * else.
 *
 * Run it only from a queued job or a console command. It refuses to run
 * inside a web request: a fetch can take its whole time budget, and a page a
 * person is waiting on must never wait on somebody else's server.
 *
 * What it guarantees, on the first URL and again on every redirect hop (at
 * most {@see self::MAX_REDIRECTS}):
 * - the URL passes {@see UrlGuard}: http or https, no credentials, a standard
 *   port, no internal host names and no shorthand numeric hosts;
 * - the host is resolved here, every address it resolves to must pass
 *   {@see IpAddressPolicy}, and the connection is pinned to the address that
 *   was checked (cURL's RESOLVE list), so a DNS answer that changes between
 *   the check and the connect (rebinding) cannot move the request inside;
 * - each host is resolved once per fetch, so robots.txt and the page go to
 *   the same checked address;
 * - the site's robots.txt allows the path, read with this fetcher's product
 *   token and cached per origin;
 * - no proxy from the environment, no cookies, no Referer, a User-Agent that
 *   names Laravel AI Blog;
 * - the body is at most {@see self::$maxBytes} on the wire and again after
 *   decompression: a declared length over the cap is refused from the
 *   headers, and cURL's progress callback aborts a transfer that grows past
 *   it. The whole fetch, redirects and robots.txt included, fits in
 *   {@see self::$totalSeconds};
 * - the connection always goes through cURL. Guzzle's `stream` option is
 *   never set, since it would swap in the stream handler, which cannot pin
 *   an address (and refuses the cURL options outright).
 * - only HTML, XML (RSS and Atom included), JSON (JSON Feed included) and
 *   plain text are kept.
 *
 * Whatever comes back is untrusted data. Hand it to a model only through
 * {@see TextExtractor} and {@see UntrustedText}.
 */
final class SafeFetcher
{
    public const PRODUCT_TOKEN = 'AiBlogBot';

    public const MAX_REDIRECTS = 5;

    /**
     * Media types worth keeping.
     *
     * @var list<string>
     */
    public const ALLOWED_MEDIA_TYPES = [
        'text/html',
        'application/xhtml+xml',
        'application/xml',
        'text/xml',
        'application/rss+xml',
        'application/atom+xml',
        'application/rdf+xml',
        'application/json',
        'application/feed+json',
        'text/plain',
    ];

    private const ACCEPT = 'text/html, application/xhtml+xml, application/rss+xml, application/atom+xml, application/feed+json, application/xml;q=0.9, text/xml;q=0.9, application/json;q=0.9, text/plain;q=0.8';

    private const ROBOTS_MAX_BYTES = 512 * 1024;

    private const ROBOTS_CACHE_PREFIX = 'fetching:robots:';

    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    public function __construct(
        private readonly HostResolver $resolver,
        private readonly IpAddressPolicy $policy,
        private readonly UrlGuard $guard,
        private readonly int $maxBytes = 2 * 1024 * 1024,
        private readonly float $totalSeconds = 30.0,
        private readonly float $connectSeconds = 5.0,
        private readonly int $robotsCacheSeconds = 86400,
    ) {}

    /**
     * @throws FetchRefused when a policy says no
     * @throws FetchFailed when the server could not be reached or answered with an error
     */
    public function fetch(string $url): FetchedDocument
    {
        if (! App::runningInConsole()) {
            throw FetchRefused::because(FetchRefusal::WebRequest, $url);
        }

        $deadline = $this->now() + $this->totalSeconds;

        /** @var array<string, string> $pinned */
        $pinned = [];
        $redirects = [];
        $current = $url;

        for ($hop = 0; ; $hop++) {
            $target = $this->guard->check($current);
            $address = $this->pin($target, $pinned);

            $this->assertRobotsAllow($target, $pinned, $deadline);

            $response = $this->send($target, $address, $deadline, self::ACCEPT, $this->maxBytes, enforceMediaType: true);

            if (in_array($response->status(), self::REDIRECT_STATUSES, true)) {
                if ($hop >= self::MAX_REDIRECTS) {
                    throw FetchRefused::because(FetchRefusal::TooManyRedirects, $url);
                }

                $redirects[] = $target->url;
                $current = $this->redirectLocation($target, $response);

                continue;
            }

            if ($response->status() < 200 || $response->status() >= 300) {
                throw FetchFailed::status($target->url, $response->status());
            }

            $contentType = $response->header('Content-Type');
            $this->assertMediaType($target->url, $contentType);

            $body = $this->readBody($target->url, $response->toPsrResponse(), $this->maxBytes, $deadline);

            return new FetchedDocument(
                requestedUrl: $url,
                finalUrl: $target->url,
                status: $response->status(),
                contentType: $contentType,
                body: $body,
                sha256: hash('sha256', $body),
                fetchedAt: CarbonImmutable::now(),
                redirects: $redirects,
            );
        }
    }

    /**
     * The User-Agent every request carries.
     */
    public static function userAgent(): string
    {
        return self::PRODUCT_TOKEN.'/1.0 (Laravel AI Blog research fetcher; +'.rtrim((string) config('app.url'), '/').')';
    }

    /**
     * The address to connect to for this target: the literal IP, or the
     * host's first resolved address once every resolved address has passed
     * the policy. Each host is resolved once per fetch.
     *
     * @param  array<string, string>  $pinned
     */
    private function pin(FetchTarget $target, array &$pinned): string
    {
        if ($target->literalIp !== null) {
            return $target->literalIp;
        }

        if (isset($pinned[$target->host])) {
            return $pinned[$target->host];
        }

        $addresses = $this->resolver->resolve($target->host);

        if ($addresses === []) {
            throw FetchRefused::because(FetchRefusal::UnresolvableHost, $target->url);
        }

        foreach ($addresses as $address) {
            if (! $this->policy->allows($address)) {
                throw FetchRefused::because(FetchRefusal::BlockedAddress, $target->url);
            }
        }

        $ipv4 = array_values(array_filter($addresses, fn (string $address): bool => ! str_contains($address, ':')));

        return $pinned[$target->host] = $ipv4[0] ?? $addresses[0];
    }

    /**
     * @param  array<string, string>  $pinned
     */
    private function assertRobotsAllow(FetchTarget $target, array &$pinned, float $deadline): void
    {
        $origin = $target->origin();
        $cacheKey = self::ROBOTS_CACHE_PREFIX.hash('sha256', $origin);

        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            $rules = new RobotsRules($cached);
        } else {
            $rules = $this->fetchRobots($origin, $pinned, $deadline);
            Cache::put($cacheKey, $rules->rules, $this->robotsCacheSeconds);
        }

        if (! $rules->allows($target->pathAndQuery)) {
            throw FetchRefused::because(FetchRefusal::DisallowedByRobots, $target->url);
        }
    }

    /**
     * Reads robots.txt, following up to five redirects under the same checks
     * as any fetch. A missing file (any 4xx) allows everything; a server
     * error or no answer refuses the whole site for now and is not cached.
     *
     * @param  array<string, string>  $pinned
     */
    private function fetchRobots(string $origin, array &$pinned, float $deadline): RobotsRules
    {
        $current = $origin.'/robots.txt';

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            try {
                $target = $this->guard->check($current);
                $address = $this->pin($target, $pinned);
                $response = $this->send($target, $address, $deadline, 'text/plain', self::ROBOTS_MAX_BYTES, enforceMediaType: false);
            } catch (FetchFailed $failure) {
                throw FetchRefused::because(FetchRefusal::RobotsUnavailable, $origin.'/robots.txt', $failure->getMessage());
            } catch (FetchRefused $refusal) {
                if ($refusal->reason === FetchRefusal::OutOfTime) {
                    throw $refusal;
                }

                throw FetchRefused::because(FetchRefusal::RobotsUnavailable, $origin.'/robots.txt', $refusal->reason->value);
            }

            $status = $response->status();

            if (in_array($status, self::REDIRECT_STATUSES, true)) {
                try {
                    $current = $this->redirectLocation($target, $response);
                } catch (FetchRefused) {
                    return RobotsRules::allowAll();
                }

                continue;
            }

            if ($status >= 200 && $status < 300) {
                $body = $this->readBody($target->url, $response->toPsrResponse(), self::ROBOTS_MAX_BYTES, $deadline, truncate: true);

                return RobotsRules::parse($body, self::PRODUCT_TOKEN);
            }

            if ($status >= 400 && $status < 500 && $status !== 429) {
                return RobotsRules::allowAll();
            }

            throw FetchRefused::because(FetchRefusal::RobotsUnavailable, $origin.'/robots.txt', 'HTTP '.$status);
        }

        return RobotsRules::allowAll();
    }

    private function send(FetchTarget $target, string $address, float $deadline, string $accept, int $maxBytes, bool $enforceMediaType): Response
    {
        $remaining = $deadline - $this->now();

        if ($remaining <= 0) {
            throw FetchRefused::because(FetchRefusal::OutOfTime, $target->url);
        }

        $pinnedAddress = str_contains($address, ':') ? '['.$address.']' : $address;

        /** @var FetchRefused|null $earlyRefusal */
        $earlyRefusal = null;

        try {
            $response = Http::withoutRedirecting()
                ->withHeaders([
                    'User-Agent' => self::userAgent(),
                    'Accept' => $accept,
                    'Accept-Encoding' => 'gzip, deflate',
                    'Accept-Language' => 'en;q=1.0, *;q=0.5',
                ])
                ->withOptions([
                    'timeout' => $remaining,
                    'connect_timeout' => min($this->connectSeconds, $remaining),
                    'cookies' => false,
                    'decode_content' => false,
                    'http_errors' => false,
                    'proxy' => ['no' => ['*']],
                    'protocols' => ['http', 'https'],
                    'curl' => [
                        CURLOPT_RESOLVE => [$target->host.':'.$target->port.':'.$pinnedAddress],
                        CURLOPT_FRESH_CONNECT => true,
                        CURLOPT_FORBID_REUSE => true,
                    ],
                    'progress' => fn (int $downloadTotal, int $downloaded): bool => $downloaded > $maxBytes || $downloadTotal > $maxBytes,
                    'on_headers' => function (ResponseInterface $headers) use ($target, $maxBytes, $enforceMediaType, &$earlyRefusal): void {
                        $earlyRefusal = $this->headerRefusal($target->url, $headers, $maxBytes, $enforceMediaType);

                        if ($earlyRefusal !== null) {
                            throw $earlyRefusal;
                        }
                    },
                ])
                ->get($target->url);
        } catch (ConnectionException|TransferException $exception) {
            if ($earlyRefusal instanceof FetchRefused) {
                throw $earlyRefusal;
            }

            if (str_contains(strtolower($exception->getMessage()), 'aborted by the progress callback')) {
                throw FetchRefused::because(FetchRefusal::TooLarge, $target->url);
            }

            if ($this->now() >= $deadline) {
                throw FetchRefused::because(FetchRefusal::OutOfTime, $target->url);
            }

            throw FetchFailed::unreachable($target->url, 'the connection failed or timed out');
        }

        $refusal = $this->headerRefusal($target->url, $response->toPsrResponse(), $maxBytes, $enforceMediaType);

        if ($refusal !== null) {
            throw $refusal;
        }

        return $response;
    }

    /**
     * What the headers alone already rule out: a declared length over the
     * cap, an encoding this fetcher does not undo, and, for a 2xx answer, a
     * media type it does not keep.
     */
    private function headerRefusal(string $url, ResponseInterface $response, int $maxBytes, bool $enforceMediaType): ?FetchRefused
    {
        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            return null;
        }

        $length = $response->getHeaderLine('Content-Length');

        if ($length !== '' && ctype_digit($length) && (int) $length > $maxBytes) {
            return FetchRefused::because(FetchRefusal::TooLarge, $url);
        }

        $encoding = strtolower(trim($response->getHeaderLine('Content-Encoding')));

        if (! in_array($encoding, ['', 'identity', 'gzip', 'x-gzip', 'deflate'], true)) {
            return FetchRefused::because(FetchRefusal::UnsupportedEncoding, $url, $encoding);
        }

        if ($enforceMediaType && ! $this->isAllowedMediaType($response->getHeaderLine('Content-Type'))) {
            return FetchRefused::because(FetchRefusal::UnsupportedContentType, $url, $this->mediaType($response->getHeaderLine('Content-Type')));
        }

        return null;
    }

    private function assertMediaType(string $url, string $contentType): void
    {
        if (! $this->isAllowedMediaType($contentType)) {
            throw FetchRefused::because(FetchRefusal::UnsupportedContentType, $url, $this->mediaType($contentType));
        }
    }

    private function isAllowedMediaType(string $contentType): bool
    {
        return in_array($this->mediaType($contentType), self::ALLOWED_MEDIA_TYPES, true);
    }

    private function mediaType(string $contentType): string
    {
        return strtolower(trim(explode(';', $contentType, 2)[0]));
    }

    private function redirectLocation(FetchTarget $target, Response $response): string
    {
        $location = trim($response->header('Location'));

        if ($location === '') {
            throw FetchRefused::because(FetchRefusal::InvalidRedirect, $target->url);
        }

        try {
            return (string) UriResolver::resolve(new Uri($target->url), new Uri($location))->withFragment('');
        } catch (InvalidArgumentException) {
            throw FetchRefused::because(FetchRefusal::InvalidRedirect, $target->url);
        }
    }

    /**
     * Reads the body in chunks, undoing gzip or deflate as it goes, and stops
     * as soon as it passes the cap or the deadline. A robots.txt is cut at
     * the cap instead of refused, as RFC 9309 allows.
     */
    private function readBody(string $url, ResponseInterface $response, int $maxBytes, float $deadline, bool $truncate = false): string
    {
        $encoding = strtolower(trim($response->getHeaderLine('Content-Encoding')));
        $inflater = match ($encoding) {
            'gzip', 'x-gzip' => inflate_init(ZLIB_ENCODING_GZIP),
            'deflate' => inflate_init(ZLIB_ENCODING_DEFLATE),
            default => null,
        };

        $stream = $response->getBody();
        $body = '';
        $wireBytes = 0;

        while (! $stream->eof()) {
            $chunk = $stream->read(65536);

            if ($chunk === '') {
                break;
            }

            $wireBytes += strlen($chunk);

            if ($inflater !== null) {
                $chunk = @inflate_add($inflater, $chunk, ZLIB_SYNC_FLUSH);

                if ($chunk === false) {
                    throw FetchFailed::unreachable($url, 'the compressed body is corrupt');
                }
            }

            $body .= $chunk;

            if (strlen($body) > $maxBytes || $wireBytes > $maxBytes) {
                if ($truncate) {
                    return substr($body, 0, $maxBytes);
                }

                throw FetchRefused::because(FetchRefusal::TooLarge, $url);
            }

            if ($this->now() > $deadline) {
                throw FetchRefused::because(FetchRefusal::OutOfTime, $url);
            }
        }

        return $body;
    }

    private function now(): float
    {
        return hrtime(true) / 1e9;
    }
}
