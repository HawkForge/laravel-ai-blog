<?php

declare(strict_types=1);

namespace AiBlog\Fetching;

/**
 * A URL that passed {@see UrlGuard}: http or https, no credentials, a
 * standard port, and a host that is either a name to resolve or a canonical
 * IP literal. `url` is the normalised form that is actually requested, with
 * no fragment and the host in lower-case ASCII.
 */
final readonly class FetchTarget
{
    public function __construct(
        public string $url,
        public string $scheme,
        public string $host,
        public int $port,
        public string $pathAndQuery,
        public ?string $literalIp = null,
    ) {}

    /**
     * Scheme, host and port: whose robots.txt applies.
     */
    public function origin(): string
    {
        $host = str_contains($this->host, ':') ? '['.$this->host.']' : $this->host;
        $isDefaultPort = ($this->scheme === 'http' && $this->port === 80) || ($this->scheme === 'https' && $this->port === 443);

        return $this->scheme.'://'.$host.($isDefaultPort ? '' : ':'.$this->port);
    }
}
