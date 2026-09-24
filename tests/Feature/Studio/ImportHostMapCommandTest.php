<?php

namespace Tests\Feature\Studio;

use App\Models\Masjid;
use App\Models\MasjidDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\MakesStudioDomains;
use Tests\TestCase;

/**
 * domains:import-host-map records the renderer's live map. It writes
 * production data, so what is pinned here is mostly what it must NOT do: write
 * on a dry run, write part of a map, re-point a host, or write twice.
 */
class ImportHostMapCommandTest extends TestCase
{
    use MakesStudioDomains;
    use RefreshDatabase;

    private Masjid $mec;
    private Masjid $burlington;

    /** @var array<string, array{0: int, 1: array<string, string>}> host => [status, headers] */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mec = $this->makeOrg(['name' => 'MEC']);
        $this->burlington = $this->makeOrg(['name' => 'Burlington']);

        $this->resolveTo(['93.184.216.34']);

        // What the live hosts answer: the renderer's own tenant header where our
        // site serves, a redirect or someone else's page where it does not. A
        // test may change an answer mid-way, which a list of Http::fake stubs
        // could not do (the first stub registered always wins).
        $this->answers = [
            'mec.manara.hopetechapps.com' => [200, ['x-manara-tenant' => (string) $this->mec->id]],
            'www.burlingtonmasjid.com' => [200, ['x-manara-tenant' => (string) $this->burlington->id]],
            'burlingtonmasjid.com' => [307, ['Location' => 'https://www.burlingtonmasjid.com/']],
            'new.burlingtonmasjid.com' => [404, []],
            'meccharlotte.org' => [200, []],
        ];

        Http::fake(function (Request $request) {
            [$status, $headers] = $this->answers[parse_url($request->url(), PHP_URL_HOST)] ?? [404, []];

            return Http::response('', $status, $headers);
        });
    }

    private function map(): string
    {
        return json_encode([
            'mec.manara.hopetechapps.com' => $this->mec->id,
            'www.burlingtonmasjid.com' => $this->burlington->id,
            'burlingtonmasjid.com' => (string) $this->burlington->id,
            'new.burlingtonmasjid.com' => $this->burlington->id,
            'meccharlotte.org' => $this->mec->id,
            'mec-web.pages.dev' => $this->mec->id,
            'localhost' => $this->burlington->id,
            '127.0.0.1' => $this->burlington->id,
        ]);
    }

    private const APEXES = [
        'www.burlingtonmasjid.com:burlingtonmasjid.com',
        'burlingtonmasjid.com:burlingtonmasjid.com',
        'new.burlingtonmasjid.com:burlingtonmasjid.com',
        'meccharlotte.org:meccharlotte.org',
    ];

    private function import(array $options = [], ?string $map = null)
    {
        return $this->artisan('domains:import-host-map', array_merge([
            'map' => $map ?? $this->map(),
            '--apex' => self::APEXES,
        ], $options));
    }

    /** @return array<string, string> host => status */
    private function recorded(): array
    {
        return MasjidDomain::query()->orderBy('host')->pluck('status', 'host')->all();
    }

    #[Test]
    public function probe_mismatches_become_reserved_and_matches_become_manual(): void
    {
        $this->import(['--execute' => true])->assertExitCode(0);

        $this->assertSame([
            'burlingtonmasjid.com' => MasjidDomain::STATUS_RESERVED,
            'mec.manara.hopetechapps.com' => MasjidDomain::STATUS_MANUAL,
            'meccharlotte.org' => MasjidDomain::STATUS_RESERVED,
            'new.burlingtonmasjid.com' => MasjidDomain::STATUS_RESERVED,
            'www.burlingtonmasjid.com' => MasjidDomain::STATUS_MANUAL,
        ], $this->recorded(), 'localhost, the IP literal and *.pages.dev must be skipped');

        $managed = MasjidDomain::query()->where('host', 'mec.manara.hopetechapps.com')->first();
        $this->assertSame($this->mec->id, (int) $managed->masjid_id);
        $this->assertSame(MasjidDomain::KIND_MANAGED_SUBDOMAIN, $managed->kind);
        $this->assertSame('hopetechapps.com', $managed->zone_apex);
        $this->assertSame(MasjidDomain::SOURCE_IMPORTED, $managed->source);
        $this->assertSame(MasjidDomain::VERIFIED_BY_PROBE, $managed->verified_by);
        $this->assertNotNull($managed->verified_at);
        $this->assertNotNull($managed->serving_confirmed_at);

        $reserved = MasjidDomain::query()->where('host', 'meccharlotte.org')->first();
        $this->assertSame(MasjidDomain::KIND_CUSTOM, $reserved->kind);
        $this->assertSame('meccharlotte.org', $reserved->zone_apex);
        $this->assertNull($reserved->serving_confirmed_at);
        $this->assertNull($reserved->verified_by);

        $this->assertSame(0, MasjidDomain::query()->where('status', MasjidDomain::STATUS_ACTIVE)->count());
        $this->assertSame(0, MasjidDomain::query()->where('source', '!=', MasjidDomain::SOURCE_IMPORTED)->count());
    }

    #[Test]
    public function dry_run_writes_nothing(): void
    {
        $this->import(['--dry-run' => true])
            ->expectsOutputToContain('Mode: dry run')
            ->expectsOutputToContain('mec.manara.hopetechapps.com')
            ->expectsOutputToContain('5 row(s) would be created')
            ->assertExitCode(0);

        // With neither flag it is still a dry run, and --dry-run beats --execute.
        $this->import()->expectsOutputToContain('Mode: dry run')->assertExitCode(0);
        $this->import(['--execute' => true, '--dry-run' => true])->expectsOutputToContain('Mode: dry run')->assertExitCode(0);

        $this->assertSame(0, MasjidDomain::query()->count());
    }

    #[Test]
    public function an_unknown_id_is_refused_and_nothing_is_written(): void
    {
        $gone = $this->makeOrg();
        $gone->delete();

        foreach ([999999, $gone->id] as $missing) {
            $map = json_encode(['mec.manara.hopetechapps.com' => $this->mec->id, 'www.lost.org' => $missing]);

            $this->import(['--execute' => true, '--apex' => ['www.lost.org:lost.org']], $map)
                ->expectsOutputToContain("no live organisation has id {$missing}")
                ->assertExitCode(1);
        }

        $this->assertSame(0, MasjidDomain::query()->count());
    }

    #[Test]
    public function a_custom_host_without_an_apex_is_refused(): void
    {
        $this->import(['--execute' => true, '--apex' => []])
            ->expectsOutputToContain('custom host with no --apex')
            ->assertExitCode(1);

        $this->assertSame(0, MasjidDomain::query()->count());
    }

    #[Test]
    public function the_command_is_idempotent(): void
    {
        $this->import(['--execute' => true])->assertExitCode(0);
        $first = MasjidDomain::query()->orderBy('id')->get()->map->only(['id', 'host', 'masjid_id', 'status', 'updated_at'])->all();

        $this->travel(5)->minutes();

        $this->import(['--execute' => true])
            ->expectsOutputToContain('Nothing to create')
            ->assertExitCode(0);

        $this->assertEquals($first, MasjidDomain::query()->orderBy('id')->get()->map->only(['id', 'host', 'masjid_id', 'status', 'updated_at'])->all());
    }

    #[Test]
    public function it_never_re_points_a_host(): void
    {
        $this->makeDomain($this->burlington, 'meccharlotte.org', MasjidDomain::STATUS_RESERVED, ['zone_apex' => 'meccharlotte.org']);

        $this->import(['--execute' => true])
            ->expectsOutputToContain("already held by masjid {$this->burlington->id}")
            ->assertExitCode(1);

        $this->assertSame(['meccharlotte.org' => MasjidDomain::STATUS_RESERVED], $this->recorded());
        $this->assertSame($this->burlington->id, (int) MasjidDomain::query()->where('host', 'meccharlotte.org')->value('masjid_id'));
    }

    #[Test]
    public function a_reserved_row_is_never_advanced_by_a_re_run(): void
    {
        $this->import(['--execute' => true])->assertExitCode(0);

        // new.burlingtonmasjid.com is attached later and starts answering.
        $this->answers['new.burlingtonmasjid.com'] = [200, ['x-manara-tenant' => (string) $this->burlington->id]];

        $this->import(['--execute' => true])->assertExitCode(0);

        $this->assertSame(MasjidDomain::STATUS_RESERVED, $this->recorded()['new.burlingtonmasjid.com']);
    }

    #[Test]
    public function one_host_mapped_to_two_organisations_is_refused(): void
    {
        $map = '{"www.burlingtonmasjid.com": ' . $this->burlington->id . ', "WWW.BurlingtonMasjid.com.": ' . $this->mec->id . '}';

        $this->import(['--execute' => true], $map)
            ->expectsOutputToContain("also mapped to masjid {$this->burlington->id}")
            ->assertExitCode(1);

        $this->assertSame(0, MasjidDomain::query()->count());
    }
}
