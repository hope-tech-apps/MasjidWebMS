<?php

namespace App\Support\Canary;

/**
 * One request the tenancy canary will make.
 *
 * There is no `method` property, and that is the point: the canary only ever
 * issues GET, so a Probe cannot express a write. See TenancyCanary's docblock
 * for why read-only is a hard constraint rather than a preference.
 */
final class Probe
{
    /** The request names a real organisation. This is the control. */
    public const VARIANT_TENANT = 'valid-tenant';

    /** No `masjid-id` header at all — the shape that returned 14 rows in prod. */
    public const VARIANT_ABSENT = 'no-tenant-header';

    /** `masjid-id: 0` — falsy, and the old `if ($resourceId)` treated it as absent. */
    public const VARIANT_ZERO = 'falsy-tenant-header-zero';

    /** `masjid-id:` with an empty value — the other falsy spelling. */
    public const VARIANT_EMPTY = 'falsy-tenant-header-empty';

    /** A deliberately cross-tenant endpoint (the masjid directory, the azkar library). */
    public const VARIANT_GLOBAL = 'global-endpoint';

    /**
     * @param  string  $endpoint  the route URI TEMPLATE, e.g. api/mobile/masjids/{masjid_id}/events
     * @param  string  $path  the concrete path with parameters filled in
     * @param  array<string,scalar>  $query
     * @param  array<string,string>  $headers
     * @param  int|null  $tenantId  the organisation this request ASKED FOR, null when it named none
     */
    public function __construct(
        public readonly string $endpoint,
        public readonly string $path,
        public readonly array $query,
        public readonly array $headers,
        public readonly string $variant,
        public readonly ?int $tenantId,
    ) {
    }

    /** True for the variants that name no organisation and must therefore be refused. */
    public function isTenantLess(): bool
    {
        return in_array($this->variant, [
            self::VARIANT_ABSENT,
            self::VARIANT_ZERO,
            self::VARIANT_EMPTY,
        ], true);
    }

    public function url(string $baseUrl): string
    {
        $url = rtrim($baseUrl, '/').'/'.ltrim($this->path, '/');

        return $this->query === [] ? $url : $url.'?'.http_build_query($this->query);
    }

    /**
     * The exact request, as something a human can paste into a terminal.
     *
     * A finding that says "the announcements endpoint leaked" is a bug report
     * nobody can act on at 3am. A finding that says "run this" is one they can.
     */
    public function curl(string $baseUrl): string
    {
        $parts = ['curl -sS'];

        foreach ($this->headers as $name => $value) {
            $parts[] = "-H ".escapeshellarg("{$name}: {$value}");
        }

        $parts[] = escapeshellarg($this->url($baseUrl));

        return implode(' ', $parts);
    }

    /** @return array<string,mixed> */
    public function toArray(string $baseUrl): array
    {
        return [
            'method' => 'GET',
            'endpoint' => $this->endpoint,
            'url' => $this->url($baseUrl),
            'headers' => $this->headers,
            'variant' => $this->variant,
            'tenant_id' => $this->tenantId,
            'reproduce' => $this->curl($baseUrl),
        ];
    }
}
