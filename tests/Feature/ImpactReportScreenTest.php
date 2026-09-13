<?php

namespace Tests\Feature;

use App\Models\AppointmentRequest;
use App\Models\Masjid;
use App\Models\User;
use App\Support\ImpactMetrics;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The contract between the impact report ENDPOINT and the screen that renders
 * it (`resources/vue-app/views/dashboard/ImpactReportView.vue`, T-043f).
 *
 * ImpactMetricsTest already pins what each figure MEANS. These tests pin the
 * three things a Vue screen depends on and that nothing else would catch,
 * because the SPA build does not type-check and there is no Vue test harness:
 * a payload that drifts from the TypeScript types fails silently in a browser,
 * on a page an organization prints and sends to a funder.
 *
 *  1. Every metric carries every field the cards read. A renamed or dropped key
 *     turns a figure into "undefined" on a filed document.
 *  2. Every omitted key is one the screen can put a LABEL on. `meta.omitted`
 *     carries `{key, reason}` and no label (ImpactMetricsController::report()),
 *     so the view keeps its own map mirroring the PHP catalogue. A metric added
 *     to the catalogue without a matching entry in `impactMetricLabels()` does
 *     not print a blank line — `impactMetricLabel()` humanises the machine key
 *     — which is worse: "Not included in this report" then names the figure
 *     something the server never calls it, and the three vocabulary-bearing
 *     labels lose the tenant's own term ("Active Halaqat" becomes "Active
 *     groups"). Pinned by reading the TypeScript source: there is no Vue test
 *     harness and the SPA build does not type-check, so nothing else can catch
 *     the two halves drifting apart.
 *  3. The sidebar's `requiresCrm` matches the server. The route lives inside the
 *     `crm` group; if that ever drifted, the two would disagree in one direction
 *     or the other — a menu item leading to a blank 403, or a screen offered to
 *     an organization that never bought the CRM.
 *
 * Deliberately NOT duplicated here: tenant isolation, the money/permission
 * split and the period semantics, all pinned by ImpactMetricsTest and
 * ImpactMetricsTenantIsolationTest.
 */
class ImpactReportScreenTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $tenant;

    private Masjid $community;

    protected function setUp(): void
    {
        parent::setUp();

        // Force sqlite-in-memory regardless of phpunit.xml, as ImpactMetricsTest does.
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        // Frozen so every as-of figure lands on a fixed date; 12:00 UTC is
        // 08:00 EDT the same day, so "today" is unambiguous in both frames.
        $this->travelTo(Carbon::parse('2026-08-05 12:00:00', 'UTC'));

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->tenant = app(TenantContext::class);
        $this->tenant->forgetTenant();

        $this->community = $this->makeMasjid(['org_type' => Masjid::ORG_TYPE_COMMUNITY]);
    }

    #[Test]
    public function the_report_endpoint_the_screen_calls_answers_the_same_shape_the_screen_renders(): void
    {
        $admin = $this->makeAdminFor($this->community);
        AppointmentRequest::factory()->count(2)->create(['masjid_id' => $this->community->id]);

        Sanctum::actingAs($admin);

        $response = $this->getJson($this->url($this->community) . '?from=2026-01-01&to=2026-06-30')
            ->assertOk();

        // The letterhead block. Every one of these is printed on a document
        // that leaves the organization, so a missing field is a report with no
        // period, no clock or no currency on it.
        $response->assertJsonPath('status', 'success');
        foreach (['org_type', 'timezone', 'currency', 'period', 'generated_at', 'omitted'] as $key) {
            $this->assertArrayHasKey($key, $response->json('meta'), "meta.$key");
        }
        $this->assertSame(
            ['from', 'to', 'as_of'],
            array_keys($response->json('meta.period'))
        );

        $metrics = $response->json('data.metrics');
        $this->assertNotEmpty($metrics);

        foreach ($metrics as $metric) {
            $this->assertSame(
                ['key', 'label', 'value', 'unit', 'currency', 'formatted', 'basis', 'period', 'provenance'],
                array_keys($metric),
                'ImpactReport.ts mirrors these keys field for field.'
            );

            // `basis` decides WHICH heading a card is filed under, and a stock
            // figure printed under a date range it does not cover is a false
            // statement. An unknown value would silently drop the card.
            $this->assertContains($metric['basis'], [
                ImpactMetrics::BASIS_PERIOD,
                ImpactMetrics::BASIS_AS_OF,
                ImpactMetrics::BASIS_CURRENT,
            ], $metric['key']);

            // `unit` decides whether the small-number caution applies, and
            // `formatted` is the ONLY string the screen prints — a money value
            // is minor units and the view never divides it.
            $this->assertContains($metric['unit'], [
                ImpactMetrics::UNIT_COUNT,
                ImpactMetrics::UNIT_MONEY_MINOR,
            ], $metric['key']);
            $this->assertIsString($metric['formatted']);
            $this->assertNotSame('', $metric['formatted'], $metric['key']);

            $this->assertSame(['from', 'to', 'as_of', 'timezone'], array_keys($metric['period']));

            // The definition disclosure the print rules force open. A card whose
            // definition is missing hands a funder a count with nothing saying
            // what was counted.
            $this->assertSame(['source', 'definition'], array_keys($metric['provenance']));
            $this->assertNotSame('', $metric['provenance']['source'], $metric['key']);
            $this->assertNotSame('', $metric['provenance']['definition'], $metric['key']);
        }
    }

    #[Test]
    public function an_omitted_metric_names_a_key_the_screen_can_label(): void
    {
        $admin = $this->makeAdminFor($this->community);

        // Revoked so the money family is omitted too, and BOTH omission reasons
        // are exercised in one payload.
        Role::findByName('masjid-admin')->revokePermissionTo('view donations');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($admin);

        $response = $this->getJson($this->url($this->community))->assertOk();

        $omitted = $response->json('meta.omitted');
        $this->assertNotEmpty($omitted);

        // The view's OWN map, read out of the TypeScript source. Comparing the
        // payload against ImpactMetrics' constants instead would be a tautology:
        // `meta.omitted` is built from those same constants, so it could not
        // fail for any implementation of the server, working or broken.
        $labelled = $this->vueOmissionLabelKeys();

        foreach ($omitted as $entry) {
            $this->assertSame(['key', 'reason'], array_keys($entry));

            // A key the map does not know is named by humanising it — so this
            // panel would print "Donations total" where the rest of the report
            // says "Donations received", on a page a funder reads.
            $this->assertContains(
                $entry['key'],
                $labelled,
                $entry['key'] . ' is omitted by the server but has no entry in impactMetricLabels().'
            );

            // Both reasons map to a sentence in the view. A third one would fall
            // through to "this version cannot explain why".
            $this->assertContains($entry['reason'], [
                ImpactMetrics::OMITTED_NO_DATA,
                ImpactMetrics::OMITTED_PERMISSION,
            ], $entry['key']);
        }

        // The panel exists to separate "we did not ask" from "the answer was
        // zero"; if only one reason could ever appear it would not need to.
        $reasons = array_unique(array_column($omitted, 'reason'));
        $this->assertContains(ImpactMetrics::OMITTED_PERMISSION, $reasons);
    }

    #[Test]
    public function every_metric_key_the_catalogue_can_emit_has_a_label_on_the_screen(): void
    {
        // The omitted panel can only ever exercise the keys omitted for THIS
        // tenant under THIS permission set, so the test above cannot see a new
        // metric that happens to be present. This one compares the whole
        // catalogue against the whole map: add an 18th constant to
        // ImpactMetrics without adding a line to impactMetricLabels() and it
        // fails here, naming the key.
        $labelled = $this->vueOmissionLabelKeys();

        foreach ($this->publicMetricKeys() as $key) {
            $this->assertContains(
                $key,
                $labelled,
                "ImpactMetrics emits '{$key}' but impactMetricLabels() in "
                . 'resources/vue-app/core/types/data/masjid-related/ImpactReport.ts has no label for it.'
            );
        }
    }

    #[Test]
    public function a_masjid_without_the_crm_cannot_open_the_impact_report(): void
    {
        // The sidebar item and the route both carry `requiresCrm`, which is menu
        // housekeeping rather than a boundary. THIS is the boundary: the route
        // sits inside the `crm` group. If it drifted out, an organization that
        // never bought the CRM would get a funder report off its records.
        $admin = $this->makeAdminFor($this->community);
        $this->community->forceFill(['crm_enabled' => false])->save();

        Sanctum::actingAs($admin);

        $this->getJson($this->url($this->community))->assertStatus(403);
    }

    // ------------------------------------------------------------- helpers

    /**
     * Every metric key the catalogue can emit, read off the class constants
     * rather than retyped — the point of the test is that the view's map covers
     * the catalogue, so a hand-written list here would drift with it.
     *
     * @return array<int,string>
     */
    private function publicMetricKeys(): array
    {
        $constants = (new ReflectionClass(ImpactMetrics::class))->getConstants();

        return array_values(array_filter(
            $constants,
            fn ($value, $name) => is_string($value)
                && ! str_starts_with($name, 'BASIS_')
                && ! str_starts_with($name, 'UNIT_')
                && ! str_starts_with($name, 'OMITTED_'),
            ARRAY_FILTER_USE_BOTH
        ));
    }

    /**
     * The keys of `impactMetricLabels()` in the view's own types file.
     *
     * Read out of the TypeScript SOURCE on purpose. That map is the only thing
     * between a new catalogue key and a funder-facing panel naming the figure
     * by its humanised machine key, and this repo has no Vue test harness and
     * no type-check step in `npm run build` — so a regex over the file is the
     * only mechanism that can actually fail when the two halves drift.
     *
     * Scoped to the `return { ... };` literal inside that one function, so an
     * unrelated object elsewhere in the file cannot make a missing label look
     * present.
     *
     * @return array<int,string>
     */
    private function vueOmissionLabelKeys(): array
    {
        $path = base_path('resources/vue-app/core/types/data/masjid-related/ImpactReport.ts');

        $this->assertFileExists(
            $path,
            'The impact screen\'s label map moved. This test pins the PHP catalogue against it, '
            . 'so point it at the new location rather than deleting the assertion.'
        );

        $source = (string) file_get_contents($path);

        $this->assertSame(
            1,
            preg_match('/function\s+impactMetricLabels\s*\(.*?\breturn\s*\{(.*?)\n\s*\};/s', $source, $matches),
            'Could not find the impactMetricLabels() return literal in ImpactReport.ts.'
        );

        preg_match_all('/^\s*([a-z0-9_]+)\s*:/m', $matches[1], $keys);

        $this->assertNotEmpty($keys[1], 'impactMetricLabels() returned no keys this test could read.');

        return $keys[1];
    }

    private function makeMasjid(array $overrides = []): Masjid
    {
        return Masjid::create(array_merge([
            'name' => 'Test Org ' . uniqid(),
            'email' => 'org-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'timezone' => 'America/New_York',
            'crm_enabled' => true,
        ], $overrides));
    }

    private function makeAdminFor(Masjid $masjid): User
    {
        $admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        $masjid->user_id = $admin->id;
        $masjid->save();

        return $admin;
    }

    private function url(Masjid $masjid): string
    {
        return '/api/admin/masjids/' . $masjid->id . '/impact/report';
    }
}
