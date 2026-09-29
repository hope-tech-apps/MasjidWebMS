<?php

namespace Tests\Feature\Studio\Concerns;

use App\Models\Masjid;
use App\Models\MasjidDomain;

/**
 * A live web address Studio attached and created every object for (W2 S3),
 * plus the Cloudflare routes that read and delete those objects. Used with
 * FakesCloudflare and MakesStudioDomains.
 */
trait MakesDetachableDomains
{
    private const HOST = 'www.new-masjid.org';

    private const ZONE = 'zone-new';

    private const RECORD = 'rec-1';

    private function pagesId(string $host = self::HOST): string
    {
        return 'pd-' . md5($host);
    }

    /** A row Studio attached, whose CNAME and Pages domain its own POSTs made, seen serving. */
    private function attachedRow(Masjid $org, array $attributes = []): MasjidDomain
    {
        return $this->makeDomain($org, self::HOST, MasjidDomain::STATUS_ACTIVE, array_merge([
            'zone_apex' => 'new-masjid.org',
            'verified_by' => MasjidDomain::VERIFIED_BY_CLOUDFLARE,
            'verified_at' => now(),
            'serving_confirmed_at' => now(),
            'cf_zone_id' => self::ZONE,
            'cf_dns_record_id' => self::RECORD,
            'cf_dns_record_created' => true,
            'cf_pages_domain_id' => $this->pagesId(),
            'cf_pages_domain_created' => true,
        ], $attributes));
    }

    private function pagesPath(string $host = self::HOST): string
    {
        return '/accounts/*/pages/projects/manara-renderer/domains/' . $host;
    }

    private function recordPath(): string
    {
        return '/zones/' . self::ZONE . '/dns_records/' . self::RECORD;
    }

    /**
     * Cloudflare as Studio left it: the Pages domain and the CNAME exist and
     * are unchanged, and both deletes succeed.
     *
     * @return array<string, mixed>
     */
    private function cloudflareAsStudioLeftIt(): array
    {
        return [
            'GET ' . $this->pagesPath() => $this->cfOk($this->pagesDomainBody(self::HOST, 'active')),
            'DELETE ' . $this->pagesPath() => $this->cfOk([]),
            'GET ' . $this->recordPath() => $this->cfOk($this->dnsRecord(self::HOST, 'CNAME', 'manara-renderer.pages.dev', self::RECORD) + ['zone_id' => self::ZONE]),
            'DELETE ' . $this->recordPath() => $this->cfOk(['id' => self::RECORD]),
        ];
    }

    /** @return list<string> "METHOD path" for every Cloudflare request, relative to the API base */
    private function cloudflareCalls(): array
    {
        return array_map(fn (string $line) => str_replace(' ' . self::API, ' ', $line), $this->sentToCloudflare());
    }

    /** @return list<string> the DELETEs sent, relative to the API base */
    private function deletes(): array
    {
        return array_values(array_filter($this->cloudflareCalls(), fn (string $line) => str_starts_with($line, 'DELETE ')));
    }
}
