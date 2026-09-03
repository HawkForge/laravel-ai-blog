<?php

declare(strict_types=1);

namespace AiBlog\Fetching;

/**
 * Which IP addresses a research fetch may connect to: public unicast only.
 *
 * IPv4 is checked against a list of special-purpose ranges (IANA's registry
 * plus cloud metadata addresses that sit in public space). IPv6 is checked the
 * other way round: only global unicast (2000::/3) is considered at all, minus
 * its documentation, benchmarking and tunnelling ranges. Addresses that carry
 * an IPv4 address inside them (IPv4-mapped, IPv4-compatible, NAT64 and 6to4)
 * are judged by the IPv4 address they carry, so `::ffff:127.0.0.1` is
 * loopback, not "some IPv6 address".
 *
 * Input must be a canonical address as {@see inet_pton()} reads it. Shorthand
 * spellings such as `2130706433`, `0177.0.0.1` or `127.1` are not addresses
 * here; the URL check refuses those hosts before they get this far.
 */
final class IpAddressPolicy
{
    /**
     * IPv4 ranges never fetched from.
     *
     * @var list<string>
     */
    private const BLOCKED_IPV4 = [
        '0.0.0.0/8',          // "this network"
        '10.0.0.0/8',         // private
        '100.64.0.0/10',      // shared address space (CGNAT); also Alibaba Cloud metadata
        '127.0.0.0/8',        // loopback
        '169.254.0.0/16',     // link-local; AWS, GCP, Azure and others' metadata
        '172.16.0.0/12',      // private
        '192.0.0.0/24',       // IETF protocol assignments; Oracle Cloud metadata
        '192.0.2.0/24',       // documentation
        '192.31.196.0/24',    // AS112
        '192.52.193.0/24',    // AMT
        '192.88.99.0/24',     // deprecated 6to4 relay anycast
        '192.168.0.0/16',     // private
        '192.175.48.0/24',    // AS112 direct delegation
        '198.18.0.0/15',      // benchmarking
        '198.51.100.0/24',    // documentation
        '203.0.113.0/24',     // documentation
        '224.0.0.0/4',        // multicast
        '240.0.0.0/4',        // reserved, including 255.255.255.255
        '168.63.129.16/32',   // Azure wire server, public space but host-only
    ];

    /**
     * Parts of global unicast IPv6 never fetched from. Everything outside
     * 2000::/3 is refused before these are looked at.
     *
     * @var list<string>
     */
    private const BLOCKED_IPV6_GLOBAL = [
        '2001::/23',          // IETF protocol assignments: Teredo, ORCHID, benchmarking
        '2001:db8::/32',      // documentation
        '3fff::/20',          // documentation
    ];

    public function allows(string $ip): bool
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            return $this->allowsIpv4($packed);
        }

        return $this->allowsIpv6($packed);
    }

    /**
     * The IPv4 address an IPv6 address stands for, when it carries one in a
     * form that can reach an IPv4 host: IPv4-mapped, IPv4-compatible, NAT64
     * (64:ff9b::/96) and 6to4 (2002::/16).
     */
    public function embeddedIpv4(string $ip): ?string
    {
        $packed = @inet_pton($ip);

        if ($packed === false || strlen($packed) !== 16) {
            return null;
        }

        $embedded = $this->embeddedIpv4Packed($packed);

        return $embedded === null ? null : (string) inet_ntop($embedded);
    }

    private function allowsIpv4(string $packed): bool
    {
        foreach (self::BLOCKED_IPV4 as $range) {
            if ($this->inRange($packed, $range)) {
                return false;
            }
        }

        return true;
    }

    private function allowsIpv6(string $packed): bool
    {
        $embedded = $this->embeddedIpv4Packed($packed);

        if ($embedded !== null) {
            return $this->allowsIpv4($embedded);
        }

        if (! $this->inRange($packed, '2000::/3')) {
            return false;
        }

        foreach (self::BLOCKED_IPV6_GLOBAL as $range) {
            if ($this->inRange($packed, $range)) {
                return false;
            }
        }

        return true;
    }

    private function embeddedIpv4Packed(string $packed): ?string
    {
        if ($this->inRange($packed, '::ffff:0:0/96') || $this->inRange($packed, '::/96')
            || $this->inRange($packed, '64:ff9b::/96')) {
            return substr($packed, 12, 4);
        }

        if ($this->inRange($packed, '2002::/16')) {
            return substr($packed, 2, 4);
        }

        return null;
    }

    private function inRange(string $packed, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $networkPacked = (string) inet_pton($network);

        if (strlen($networkPacked) !== strlen($packed)) {
            return false;
        }

        $bits = (int) $bits;
        $wholeBytes = intdiv($bits, 8);

        if (strncmp($packed, $networkPacked, $wholeBytes) !== 0) {
            return false;
        }

        $remainingBits = $bits % 8;

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($packed[$wholeBytes]) & $mask) === (ord($networkPacked[$wholeBytes]) & $mask);
    }
}
