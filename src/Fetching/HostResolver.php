<?php

declare(strict_types=1);

namespace ByteMinus\AiBlog\Fetching;

/**
 * Turns a host name into the IP addresses it points at, so the fetcher can
 * judge every address itself and then connect to one it judged. Tests swap
 * in a fake to play out private answers and DNS rebinding. The package's
 * service provider binds it to {@see SystemHostResolver}.
 */
interface HostResolver
{
    /**
     * Every A and AAAA address the name resolves to, as canonical strings.
     * An empty list means the name does not resolve.
     *
     * @return list<string>
     */
    public function resolve(string $host): array;
}
