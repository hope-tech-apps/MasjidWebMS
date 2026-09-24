<?php

namespace App\Support;

/**
 * Hosts Studio will never record for an organisation, whatever HostName says
 * about their spelling.
 *
 * Every path that WRITES a host applies this after HostName::normalize:
 * StudioDomainCheckRequest (S3), StoreMasjidDomainRequest (S7), the
 * `web_domain.custom_host` provision input (S8) and domains:import-host-map. It
 * is not part of HostName because HostName is shared byte-for-byte with the
 * renderer, whose own map holds `localhost` (see HostName's docblock).
 *
 * What is refused, and why:
 *  - a single label (`intranet`) and `localhost`: no public certificate can be
 *    issued for either, and a probe of one would reach our own network;
 *  - `.localhost`, `.local`, `.internal`: reserved or private-use names that
 *    resolve, if at all, somewhere other than the public internet;
 *  - `.pages.dev`, `.workers.dev`: Cloudflare's own names. A project's
 *    `*.pages.dev` address is not a domain anyone can attach, and recording one
 *    would let an organisation claim another project's address.
 */
final class WritableHost
{
    private const REFUSED_SUFFIXES = ['.localhost', '.local', '.internal', '.pages.dev', '.workers.dev'];

    /**
     * Why a NORMALISED host may not be written, or null when it may.
     */
    public static function refusal(string $host): ?string
    {
        if ($host === 'localhost') {
            return 'localhost is not a public host.';
        }

        if (substr_count($host, '.') < 1) {
            return 'A host needs at least two labels, like example.org.';
        }

        foreach (self::REFUSED_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix) || $host === substr($suffix, 1)) {
                return "Hosts ending {$suffix} cannot be attached.";
            }
        }

        return null;
    }
}
