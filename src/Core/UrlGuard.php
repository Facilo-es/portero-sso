<?php

namespace facilo\portero\Core;

/** Metadata and discovery addresses come from an admin but are fetched by the server, so in production they must be public HTTPS. */
final class UrlGuard
{
    public static function allowed(string $url, ?callable $resolve = null): bool
    {
        return self::pin($url, $resolve) !== null;
    }

    /**
     * The checked address, so the fetch can use it and a second lookup can't send the request somewhere else.
     *
     * @return array{host:string, port:int, ip:string}|null null when the address isn't allowed
     */
    public static function pin(string $url, ?callable $resolve = null): ?array
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return null;
        }
        // dns_get_record needs a full resolver, which some containers lack, so the system lookup backs it up.
        $resolve ??= static fn(string $host) => array_merge(
            array_column(@dns_get_record($host, DNS_A) ?: [], 'ip'),
            array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'),
        ) ?: (gethostbynamel($host) ?: []);
        $host = trim($parts['host'], '[]');
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $resolve($host);
        if ($addresses === []) {
            return null;
        }
        foreach ($addresses as $address) {
            if (!self::isPublic($address)) {
                return null;
            }
        }
        return ['host' => $parts['host'], 'port' => (int) ($parts['port'] ?? 443), 'ip' => $addresses[0]];
    }

    private static function isPublic(string $address): bool
    {
        $bytes = @inet_pton($address);
        if ($bytes === false) {
            return false;
        }
        $embedded = self::embeddedIpv4($bytes);
        if ($embedded !== null) {
            return self::isPublic((string) inet_ntop($embedded));
        }
        $plain = (string) inet_ntop($bytes);
        return (bool) filter_var($plain, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) && !self::inExtraRange($plain);
    }

    /** IPv6 forms that carry an IPv4 address: mapped, compatible, NAT64 and 6to4. Each gets the IPv4 checks. */
    private static function embeddedIpv4(string $bytes): ?string
    {
        if (strlen($bytes) !== 16) {
            return null;
        }
        $prefix = substr($bytes, 0, 12);
        if (in_array($prefix, [str_repeat("\0", 12), str_repeat("\0", 10) . "\xff\xff", hex2bin('0064ff9b0000000000000000')], true)) {
            return substr($bytes, 12);
        }
        return substr($bytes, 0, 2) === "\x20\x02" ? substr($bytes, 2, 4) : null;
    }

    /** Ranges PHP's filters count as public: 100.64.0.0/10, carrier-grade NAT, 198.18.0.0/15, benchmarking, and 192.0.0.0/24, protocol use. */
    private static function inExtraRange(string $address): bool
    {
        $long = ip2long($address);
        return $long !== false && (($long & 0xFFC00000) === 0x64400000 || ($long & 0xFFFE0000) === 0xC6120000 || ($long & 0xFFFFFF00) === 0xC0000000);
    }
}
