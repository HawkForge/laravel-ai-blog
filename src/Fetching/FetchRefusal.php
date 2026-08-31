<?php

declare(strict_types=1);

namespace AiBlog\Fetching;

/**
 * Why the fetcher said no. Stored as a string wherever a refusal is recorded.
 */
enum FetchRefusal: string
{
    case InvalidUrl = 'invalid_url';
    case UnsupportedScheme = 'unsupported_scheme';
    case CredentialsInUrl = 'credentials_in_url';
    case DisallowedPort = 'disallowed_port';
    case BlockedHostname = 'blocked_hostname';
    case NonCanonicalAddress = 'non_canonical_address';
    case BlockedAddress = 'blocked_address';
    case UnresolvableHost = 'unresolvable_host';
    case TooManyRedirects = 'too_many_redirects';
    case InvalidRedirect = 'invalid_redirect';
    case UnsupportedContentType = 'unsupported_content_type';
    case UnsupportedEncoding = 'unsupported_encoding';
    case TooLarge = 'too_large';
    case DisallowedByRobots = 'disallowed_by_robots';
    case RobotsUnavailable = 'robots_unavailable';
    case OutOfTime = 'out_of_time';
    case WebRequest = 'web_request';

    public function describe(): string
    {
        return match ($this) {
            self::InvalidUrl => 'the URL is not a valid absolute URL',
            self::UnsupportedScheme => 'only http and https URLs are fetched',
            self::CredentialsInUrl => 'URLs carrying a user name or password are not fetched',
            self::DisallowedPort => 'only the standard web ports are fetched',
            self::BlockedHostname => 'the host name points inside a private network',
            self::NonCanonicalAddress => 'numeric hosts must be written as a plain dotted IPv4 or bracketed IPv6 address',
            self::BlockedAddress => 'the host resolves to a private, loopback, link-local, shared, multicast, reserved or cloud metadata address',
            self::UnresolvableHost => 'the host name does not resolve',
            self::TooManyRedirects => 'the URL redirects too many times',
            self::InvalidRedirect => 'a redirect did not name a usable location',
            self::UnsupportedContentType => 'the response is not HTML, XML, a feed, JSON or plain text',
            self::UnsupportedEncoding => 'the response uses a content encoding the fetcher does not decode',
            self::TooLarge => 'the response is larger than the fetcher keeps',
            self::DisallowedByRobots => 'the site\'s robots.txt does not allow this path',
            self::RobotsUnavailable => 'the site\'s robots.txt could not be read, so nothing on the site is fetched for now',
            self::OutOfTime => 'the fetch ran past its total time limit',
            self::WebRequest => 'research fetches run only in queued jobs or console commands, never in a web request',
        };
    }
}
