<?php

declare(strict_types=1);

namespace AiBlog\Fetching;

/**
 * The operating system's resolver: `gethostbynamel()` for IPv4, which goes
 * through getaddrinfo and so sees /etc/hosts as well as DNS, and a DNS AAAA
 * lookup for IPv6.
 */
final class SystemHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $addresses = [];

        $ipv4 = @gethostbynamel($host);

        if (is_array($ipv4)) {
            foreach ($ipv4 as $address) {
                $addresses[] = $address;
            }
        }

        $records = @dns_get_record($host, DNS_AAAA);

        if (is_array($records)) {
            foreach ($records as $record) {
                if (($record['type'] ?? null) === 'AAAA' && isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
