<?php

namespace Tests\Feature\Studio\Concerns;

use App\Models\Masjid;
use App\Models\MasjidDomain;
use App\Services\Domains\HostResolver;

/**
 * Organisations and `masjid_domains` rows for the S3 tests, plus a DNS answer
 * the test chooses, so no test here ever resolves or fetches a real host.
 */
trait MakesStudioDomains
{
    private function makeOrg(array $attributes = []): Masjid
    {
        return Masjid::create(array_merge([
            'name' => 'Studio Domains Org ' . uniqid(),
            'email' => 'domains' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0,
        ], $attributes));
    }

    private function makeDomain(Masjid $org, string $host, string $status = MasjidDomain::STATUS_PENDING, array $attributes = []): MasjidDomain
    {
        return MasjidDomain::create(array_merge([
            'masjid_id' => $org->id,
            'host' => $host,
            'kind' => MasjidDomain::KIND_CUSTOM,
            'zone_apex' => implode('.', array_slice(explode('.', $host), -2)),
            'status' => $status,
        ], $attributes));
    }

    /** @param  array<string, list<string>>|list<string>  $addresses  per host, or one answer for every host */
    private function resolveTo(array $addresses): void
    {
        $this->app->instance(HostResolver::class, new class($addresses) extends HostResolver
        {
            public array $asked = [];

            public function __construct(private readonly array $answers)
            {
            }

            public function addresses(string $host): array
            {
                $this->asked[] = $host;

                return array_is_list($this->answers) ? $this->answers : ($this->answers[$host] ?? []);
            }
        });
    }
}
