<?php

namespace App\Services\Domains;

/**
 * DNS for the domain probe, behind a class so a test can decide what a host
 * resolves to without a network, and so the probe checks exactly the addresses
 * it then connects to (DomainProbe pins them).
 */
class HostResolver
{
    /**
     * Every A and AAAA address the host has right now, IPv4 first. Empty when
     * it does not resolve.
     *
     * @return list<string>
     */
    public function addresses(string $host): array
    {
        $v4 = [];
        $v6 = [];

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        foreach (is_array($records) ? $records : [] as $record) {
            if (isset($record['ip'])) {
                $v4[] = $record['ip'];
            } elseif (isset($record['ipv6'])) {
                $v6[] = $record['ipv6'];
            }
        }

        return array_values(array_unique(array_merge($v4, $v6)));
    }
}
