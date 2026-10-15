<?php

declare(strict_types=1);

namespace ByteMinus\AiBlog\Tests\Unit\Fetching;

use ByteMinus\AiBlog\Fetching\IpAddressPolicy;
use ByteMinus\AiBlog\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class IpAddressPolicyTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function blockedAddresses(): array
    {
        return [
            'this network' => ['0.0.0.0'],
            'private 10/8' => ['10.1.2.3'],
            'cgnat' => ['100.64.0.1'],
            'alibaba metadata' => ['100.100.100.200'],
            'loopback' => ['127.0.0.1'],
            'loopback high' => ['127.255.255.254'],
            'link-local' => ['169.254.10.10'],
            'aws and gcp metadata' => ['169.254.169.254'],
            'private 172.16/12' => ['172.31.255.255'],
            'oracle metadata' => ['192.0.0.192'],
            'documentation 192.0.2' => ['192.0.2.1'],
            'private 192.168' => ['192.168.1.1'],
            'benchmarking' => ['198.18.0.1'],
            'documentation 203.0.113' => ['203.0.113.5'],
            'multicast' => ['224.0.0.1'],
            'reserved' => ['240.0.0.1'],
            'broadcast' => ['255.255.255.255'],
            'azure wire server' => ['168.63.129.16'],
            'ipv6 unspecified' => ['::'],
            'ipv6 loopback' => ['::1'],
            'ipv4-mapped loopback' => ['::ffff:127.0.0.1'],
            'ipv4-mapped metadata' => ['::ffff:169.254.169.254'],
            'ipv4-mapped private in hex' => ['::ffff:a00:1'],
            'ipv4-compatible loopback' => ['::127.0.0.1'],
            'nat64 private' => ['64:ff9b::10.0.0.1'],
            'nat64 local use' => ['64:ff9b:1::1'],
            '6to4 wrapping loopback' => ['2002:7f00:1::1'],
            '6to4 wrapping metadata' => ['2002:a9fe:a9fe::1'],
            'teredo' => ['2001:0:4136:e378:8000:63bf:3fff:fdd2'],
            'ipv6 documentation' => ['2001:db8::1'],
            'ipv6 documentation 3fff' => ['3fff::1'],
            'unique local' => ['fd00::1'],
            'aws ipv6 metadata' => ['fd00:ec2::254'],
            'link-local ipv6' => ['fe80::1'],
            'site-local ipv6' => ['fec0::1'],
            'multicast ipv6' => ['ff02::1'],
            'discard prefix' => ['100::1'],
            'not an address' => ['not-an-ip'],
            'shorthand ipv4' => ['127.1'],
            'decimal ipv4' => ['2130706433'],
        ];
    }

    #[DataProvider('blockedAddresses')]
    public function test_it_blocks_non_public_addresses(string $address): void
    {
        $this->assertFalse((new IpAddressPolicy)->allows($address));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function publicAddresses(): array
    {
        return [
            'public ipv4' => ['93.184.216.34'],
            'public ipv4 near private range' => ['172.32.0.1'],
            'public ipv4 near cgnat' => ['100.128.0.1'],
            'public ipv6' => ['2606:2800:220:1:248:1893:25c8:1946'],
            'ipv4-mapped public' => ['::ffff:93.184.216.34'],
            '6to4 wrapping public' => ['2002:5db8:d822::1'],
        ];
    }

    #[DataProvider('publicAddresses')]
    public function test_it_allows_public_unicast_addresses(string $address): void
    {
        $this->assertTrue((new IpAddressPolicy)->allows($address));
    }

    public function test_it_reads_the_ipv4_address_inside_an_ipv6_one(): void
    {
        $policy = new IpAddressPolicy;

        $this->assertSame('127.0.0.1', $policy->embeddedIpv4('::ffff:7f00:1'));
        $this->assertSame('169.254.169.254', $policy->embeddedIpv4('2002:a9fe:a9fe::'));
        $this->assertNull($policy->embeddedIpv4('2606:2800:220:1::1'));
    }
}
