<?php

namespace Tests\Feature;

use App\Enums\SectionType;
use App\Models\AppointmentRequest;
use App\Models\Contact;
use App\Models\Donation;
use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Fund;
use App\Models\Masjid;
use App\Models\Section;
use App\Models\User;
use App\Support\ImpactMetrics;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The impact report's CONTRACT (T-024): the response shape a grant report is
 * assembled from, what each figure is allowed to mean, and the boundary with
 * the `impact_stats` page section T-020 shipped.
 *
 * These are pinned at the support-class layer wherever possible, because the
 * controller is a thin wrapper and the definitions — not one HTTP route — are
 * what must not regress. The HTTP tests cover only what is genuinely the
 * controller's: validation, and the permission split between the contacts and
 * donations families.
 *
 * Four things here are load-bearing and easy to break silently:
 *
 *  1. Every metric carries PROVENANCE. A funder asks "where does 6,000 come
 *     from?" and the answer has to be in the payload, not in someone's memory.
 *  2. A metric's PERIOD is the window IT covers, which is not always the window
 *     that was asked for — a stock figure describes an instant, and saying so is
 *     the difference between a defensible number and an unanswerable one.
 *  3. Money is integer minor units until the very edge.
 *  4. Nothing here writes to a page section. What is PUBLISHED stays the text an
 *     admin typed into `impact_stats`.
 *
 * Clock frozen inside August 2026 so every as-of figure is a fixed date.
 */
class ImpactMetricsTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $tenant;

    private Masjid $community;
    private Masjid $masjid;

    protected function setUp(): void
    {
        parent::setUp();

        // Force sqlite-in-memory regardless of phpunit.xml.
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        // 12:00 UTC = 08:00 EDT the same day, so "today" is unambiguously
        // 2026-08-05 in both frames.
        $this->travelTo(Carbon::parse('2026-08-05 12:00:00', 'UTC'));

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->tenant = app(TenantContext::class);
        $this->tenant->forgetTenant();

        $this->community = $this->makeMasjid(['org_type' => Masjid::ORG_TYPE_COMMUNITY]);
        $this->masjid = $this->makeMasjid(['org_type' => Masjid::ORG_TYPE_MASJID]);
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

    /** One metric out of a report, by key, or null when it was not selected. */
    private function metric(array $report, string $key): ?array
    {
        return collect($report['metrics'])->firstWhere('key', $key);
    }

    private function omittedReason(array $report, string $key): ?string
    {
        return collect($report['omitted'])->firstWhere('key', $key)['reason'] ?? null;
    }

    /** A succeeded gift on a given calendar day at the organization. */
    private function makeGift(Masjid $masjid, int $cents, string $donatedOn): Donation
    {
        return Donation::factory()->create([
            'masjid_id' => $masjid->id,
            'fund_id' => Fund::factory()->create(['masjid_id' => $masjid->id])->id,
            'contact_id' => Contact::factory()->create(['masjid_id' => $masjid->id])->id,
            'status' => 'succeeded',
            'source' => 'offline',
            'payment_method' => 'cash',
            'charged_amount' => $cents,
            'intended_amount' => $cents,
            'net_amount' => null,
            // An offline gift is dated by donated_at — a wall-calendar day at
            // the organization, which is exactly what a reporting period means.
            'donated_at' => $donatedOn,
        ]);
    }

    // ------------------------------------------------------------- the shape

    #[Test]
    public function every_metric_carries_a_key_label_value_unit_period_and_provenance(): void
    {
        $report = ImpactMetrics::forMasjid($this->community)->report();

        $this->assertNotEmpty($report['metrics']);

        foreach ($report['metrics'] as $metric) {
            $this->assertSame(
                ['key', 'label', 'value', 'unit', 'currency', 'formatted', 'basis', 'period', 'provenance'],
                array_keys($metric)
            );

            $this->assertIsString($metric['key']);
            $this->assertNotSame('', $metric['label']);
            $this->assertIsInt($metric['value']);
            $this->assertContains($metric['unit'], [ImpactMetrics::UNIT_COUNT, ImpactMetrics::UNIT_MONEY_MINOR]);
            $this->assertContains($metric['basis'], [
                ImpactMetrics::BASIS_PERIOD,
                ImpactMetrics::BASIS_AS_OF,
                ImpactMetrics::BASIS_CURRENT,
            ]);
            $this->assertSame(['from', 'to', 'as_of', 'timezone'], array_keys($metric['period']));
            $this->assertSame('America/New_York', $metric['period']['timezone']);

            // The point of the whole slice: a figure a funder can question.
            $this->assertSame(['source', 'definition'], array_keys($metric['provenance']));
            $this->assertNotSame('', $metric['provenance']['source']);
            // Long enough to actually BE a definition. A one-word placeholder
            // is how an impact report becomes unanswerable.
            $this->assertGreaterThan(60, strlen($metric['provenance']['definition']), $metric['key']);
        }
    }

    #[Test]
    public function machine_keys_are_unique(): void
    {
        // The keys are the stable contract a filed report refers back to; two
        // metrics sharing one would make a report ambiguous after the fact.
        $keys = collect(ImpactMetrics::forMasjid($this->community)->report()['metrics'])->pluck('key');

        $this->assertSame($keys->count(), $keys->unique()->count());
    }

    #[Test]
    public function meta_states_what_the_report_was_computed_under(): void
    {
        $meta = ImpactMetrics::forMasjid($this->community)->meta(['from' => '2026-01-01', 'to' => '2026-06-30']);

        $this->assertSame(Masjid::ORG_TYPE_COMMUNITY, $meta['org_type']);
        $this->assertSame('America/New_York', $meta['timezone']);
        $this->assertSame('2026-01-01', $meta['period']['from']);
        $this->assertSame('2026-06-30', $meta['period']['to']);
        // The period ended before today, so stock figures are evaluated at its
        // end rather than at now.
        $this->assertSame('2026-06-30', $meta['period']['as_of']);
    }

    // ------------------------------------------------------------- the period

    #[Test]
    public function the_period_bounds_the_flow_metrics(): void
    {
        // Three requests: one before the window, one inside, one after.
        AppointmentRequest::factory()->create([
            'masjid_id' => $this->community->id,
            'created_at' => Carbon::parse('2026-03-31 23:00:00', 'UTC'),
        ]);
        AppointmentRequest::factory()->create([
            'masjid_id' => $this->community->id,
            'created_at' => Carbon::parse('2026-05-15 12:00:00', 'UTC'),
        ]);
        AppointmentRequest::factory()->create([
            'masjid_id' => $this->community->id,
            'created_at' => Carbon::parse('2026-07-01 12:00:00', 'UTC'),
        ]);

        $report = ImpactMetrics::forMasjid($this->community)
            ->report(['from' => '2026-04-01', 'to' => '2026-06-30']);

        $received = $this->metric($report, ImpactMetrics::APPOINTMENT_REQUESTS_RECEIVED);

        $this->assertSame(1, $received['value']);
        $this->assertSame('2026-04-01', $received['period']['from']);
        $this->assertSame('2026-06-30', $received['period']['to']);
        $this->assertNull($received['period']['as_of']);
    }

    #[Test]
    public function the_to_bound_is_inclusive_of_the_day_the_admin_typed(): void
    {
        // 2026-06-30 23:30 in New York is 2026-07-01 03:30 UTC. An admin who
        // typed "to 2026-06-30" means their whole day, so this must count —
        // the same closed-at-the-next-local-midnight rule the giving dashboard
        // applies.
        AppointmentRequest::factory()->create([
            'masjid_id' => $this->community->id,
            'created_at' => Carbon::parse('2026-07-01 03:30:00', 'UTC'),
        ]);

        $report = ImpactMetrics::forMasjid($this->community)
            ->report(['from' => '2026-06-01', 'to' => '2026-06-30']);

        $this->assertSame(1, $this->metric($report, ImpactMetrics::APPOINTMENT_REQUESTS_RECEIVED)['value']);
    }

    #[Test]
    public function a_stock_metric_reports_an_as_of_date_rather_than_the_period(): void
    {
        $report = ImpactMetrics::forMasjid($this->community)
            ->report(['from' => '2026-01-01', 'to' => '2026-06-30']);

        $valid = $this->metric($report, ImpactMetrics::CREDENTIALS_VALID);

        $this->assertSame(ImpactMetrics::BASIS_AS_OF, $valid['basis']);
        $this->assertNull($valid['period']['from']);
        $this->assertNull($valid['period']['to']);
        $this->assertSame('2026-06-30', $valid['period']['as_of']);
    }

    #[Test]
    public function a_current_state_metric_says_it_describes_today(): void
    {
        $report = ImpactMetrics::forMasjid($this->community)
            ->report(['from' => '2019-01-01', 'to' => '2019-12-31']);

        $groups = $this->metric($report, ImpactMetrics::ACTIVE_GROUPS);

        // `groups.is_active` has no history, so a 2019 report still describes
        // today — and the payload says so instead of implying otherwise.
        $this->assertSame(ImpactMetrics::BASIS_CURRENT, $groups['basis']);
        $this->assertSame('2026-08-05', $groups['period']['as_of']);
    }

    #[Test]
    public function as_of_is_clamped_to_today_when_the_period_has_not_ended(): void
    {
        $report = ImpactMetrics::forMasjid($this->community)
            ->report(['from' => '2026-01-01', 'to' => '2026-12-31']);

        // Evaluating credentials against a date that has not happened would
        // report a license expiring in October as already lapsed.
        $this->assertSame(
            '2026-08-05',
            $this->metric($report, ImpactMetrics::CREDENTIALS_VALID)['period']['as_of']
        );
    }

    #[Test]
    public function no_period_at_all_means_all_time_and_says_so(): void
    {
        $this->makeGift($this->community, 12345, '2019-04-02');

        $report = ImpactMetrics::forMasjid($this->community)->report();
        $total = $this->metric($report, ImpactMetrics::DONATIONS_TOTAL);

        $this->assertSame(12345, $total['value']);
        $this->assertNull($total['period']['from']);
        $this->assertNull($total['period']['to']);
    }

    // -------------------------------------------------------------- the money

    #[Test]
    public function money_is_integer_minor_units_and_formatted_only_at_the_edge(): void
    {
        $this->makeGift($this->community, 630000, '2026-02-10');

        $total = $this->metric(
            ImpactMetrics::forMasjid($this->community)->report(),
            ImpactMetrics::DONATIONS_TOTAL
        );

        $this->assertSame(ImpactMetrics::UNIT_MONEY_MINOR, $total['unit']);
        // The VALUE never leaves minor units — a float here is the bug this
        // pins (.claude/rules/stripe-payments.md).
        $this->assertSame(630000, $total['value']);
        $this->assertIsInt($total['value']);
        $this->assertSame('USD 6,300.00', $total['formatted']);
        $this->assertSame('USD', $total['currency']);
    }

    #[Test]
    public function a_count_carries_no_currency(): void
    {
        $count = $this->metric(
            ImpactMetrics::forMasjid($this->community)->report(),
            ImpactMetrics::FORM_SUBMISSIONS
        );

        $this->assertSame(ImpactMetrics::UNIT_COUNT, $count['unit']);
        $this->assertNull($count['currency']);
    }

    #[Test]
    public function the_donation_figures_come_from_the_giving_dashboards_own_rules(): void
    {
        // Money received only. A pending intent, a failure and a reversal all
        // sit inside the window on purpose: DonationMetrics excludes them, and
        // this report must not quietly disagree with the dashboard.
        $this->makeGift($this->community, 10000, '2026-05-01');

        foreach (['pending', 'failed', 'refunded'] as $status) {
            Donation::factory()->create([
                'masjid_id' => $this->community->id,
                'fund_id' => Fund::factory()->create(['masjid_id' => $this->community->id])->id,
                'status' => $status,
                'source' => 'offline',
                'payment_method' => 'cash',
                'charged_amount' => 99999,
                'intended_amount' => 99999,
                'donated_at' => '2026-05-02',
            ]);
        }

        $report = ImpactMetrics::forMasjid($this->community)->report();

        $this->assertSame(10000, $this->metric($report, ImpactMetrics::DONATIONS_TOTAL)['value']);
        $this->assertSame(1, $this->metric($report, ImpactMetrics::DONATIONS_COUNT)['value']);
        $this->assertSame(1, $this->metric($report, ImpactMetrics::DONORS_IDENTIFIED)['value']);
    }

    #[Test]
    public function money_metrics_are_omitted_and_named_when_the_caller_lacks_view_donations(): void
    {
        $this->makeGift($this->community, 500, '2026-05-01');

        $report = ImpactMetrics::forMasjid($this->community)->report([], includeMoney: false);

        $this->assertNull($this->metric($report, ImpactMetrics::DONATIONS_TOTAL));
        // Named, not silently missing: a reader must be able to tell "not
        // permitted" from "the answer was zero".
        $this->assertSame(
            ImpactMetrics::OMITTED_PERMISSION,
            $this->omittedReason($report, ImpactMetrics::DONATIONS_TOTAL)
        );
        $this->assertSame(
            ImpactMetrics::OMITTED_PERMISSION,
            $this->omittedReason($report, ImpactMetrics::PROGRAM_FEES_COLLECTED)
        );
        // The rest of the report still comes back.
        $this->assertNotNull($this->metric($report, ImpactMetrics::FORM_SUBMISSIONS));
    }

    // ---------------------------------------------------- vertical selection

    #[Test]
    public function a_community_org_sees_the_intake_and_credential_figures_even_at_zero(): void
    {
        $report = ImpactMetrics::forMasjid($this->community)->report();

        // A funder asks about these either way, so an empty period reports zero
        // rather than dropping the line.
        $this->assertSame(0, $this->metric($report, ImpactMetrics::APPOINTMENT_REQUESTS_RECEIVED)['value']);
        $this->assertSame(0, $this->metric($report, ImpactMetrics::CREDENTIALED_VOLUNTEERS)['value']);
    }

    #[Test]
    public function a_masjid_is_not_shown_clinic_figures_it_has_no_data_for(): void
    {
        $report = ImpactMetrics::forMasjid($this->masjid)->report();

        $this->assertNull($this->metric($report, ImpactMetrics::APPOINTMENT_REQUESTS_RECEIVED));
        $this->assertSame(
            ImpactMetrics::OMITTED_NO_DATA,
            $this->omittedReason($report, ImpactMetrics::APPOINTMENT_REQUESTS_RECEIVED)
        );

        // The org-generic figures are in every vertical's default set.
        $this->assertNotNull($this->metric($report, ImpactMetrics::FORM_SUBMISSIONS));
        $this->assertNotNull($this->metric($report, ImpactMetrics::ACTIVE_GROUPS));
    }

    #[Test]
    public function a_masjid_with_real_intake_data_is_shown_it_anyway(): void
    {
        // The other half of the selection rule. A masjid running a food pantry
        // has appointment requests, and hiding them because the tenant is not
        // flagged "community" would be exactly the hardcoding
        // .claude/rules/verticals.md forbids.
        AppointmentRequest::factory()->create(['masjid_id' => $this->masjid->id]);

        $report = ImpactMetrics::forMasjid($this->masjid)->report();

        $this->assertSame(1, $this->metric($report, ImpactMetrics::APPOINTMENT_REQUESTS_RECEIVED)['value']);
    }

    #[Test]
    public function labels_use_the_tenants_own_vocabulary(): void
    {
        $masjidReport = ImpactMetrics::forMasjid($this->masjid)->report();
        $communityReport = ImpactMetrics::forMasjid($this->community)->report();

        // "Halaqat" for a masjid, "Teams" for a community org — from the
        // terminology pack, never hardcoded (.claude/rules/verticals.md).
        $this->assertSame(
            'Active ' . $this->masjid->term('groups'),
            $this->metric($masjidReport, ImpactMetrics::ACTIVE_GROUPS)['label']
        );
        $this->assertNotSame(
            $this->metric($masjidReport, ImpactMetrics::ACTIVE_GROUPS)['label'],
            $this->metric($communityReport, ImpactMetrics::ACTIVE_GROUPS)['label']
        );
    }

    #[Test]
    public function an_unrecognized_org_type_degrades_to_masjid(): void
    {
        // Never silently grant another vertical's set (Masjid::orgType()).
        $odd = $this->makeMasjid(['org_type' => Masjid::ORG_TYPE_MASJID]);
        $odd->forceFill(['org_type' => 'clinic'])->saveQuietly();

        $report = ImpactMetrics::forMasjid($odd->fresh())->report();

        $this->assertNull($this->metric($report, ImpactMetrics::APPOINTMENT_REQUESTS_RECEIVED));
    }

    // --------------------------------------------------- forms + entry counts

    #[Test]
    public function form_submissions_and_the_people_they_represent_are_separate_figures(): void
    {
        $form = Form::factory()->create(['masjid_id' => $this->community->id]);

        // One family registering four children, and one ordinary submission.
        FormResponse::create([
            'form_id' => $form->id,
            'masjid_id' => $this->community->id,
            'data' => ['full_name' => 'A household'],
            'entry_count' => 4,
            'submitted_at' => Carbon::parse('2026-05-02 12:00:00', 'UTC'),
        ]);
        FormResponse::create([
            'form_id' => $form->id,
            'masjid_id' => $this->community->id,
            'data' => ['full_name' => 'One person'],
            'entry_count' => 1,
            'submitted_at' => Carbon::parse('2026-05-03 12:00:00', 'UTC'),
        ]);

        $report = ImpactMetrics::forMasjid($this->community)->report();

        $this->assertSame(2, $this->metric($report, ImpactMetrics::FORM_SUBMISSIONS)['value']);
        $this->assertSame(5, $this->metric($report, ImpactMetrics::FORM_SUBMISSION_PEOPLE)['value']);
    }

    // -------------------------------------------- the T-020 section boundary

    #[Test]
    public function the_impact_stats_section_stays_author_supplied_and_untouched(): void
    {
        // T-020's section is the authoritative source of what is PUBLISHED, and
        // its values stay display text an admin typed. This slice must not have
        // repointed it at an aggregate.
        $this->assertFalse(SectionType::IMPACT_STATS->usesExternalData());
        $this->assertSame(
            ['heading', 'description', 'period', 'stats', 'layout', 'columns', 'background_color'],
            array_keys(SectionType::IMPACT_STATS->defaultContent())
        );

        $section = Section::create([
            'masjid_id' => $this->community->id,
            'section_type' => SectionType::IMPACT_STATS->value,
            'title' => 'Our impact',
            'content' => array_merge(SectionType::IMPACT_STATS->defaultContent(), [
                'period' => 'In 2025',
                // An audited, editorially-rounded figure. Running the report
                // must never restate it.
                'stats' => [['value' => '6,000+', 'label' => 'Patient visits', 'description' => '']],
            ]),
        ]);

        $before = Section::findOrFail($section->id)->getRawOriginal('content');

        // Seed real data that would produce a DIFFERENT number, then report.
        AppointmentRequest::factory()->count(3)->create(['masjid_id' => $this->community->id]);
        $report = ImpactMetrics::forMasjid($this->community)->report();

        $this->assertSame(3, $this->metric($report, ImpactMetrics::APPOINTMENT_REQUESTS_RECEIVED)['value']);

        // The published claim is exactly as the admin left it, and no section
        // row was created behind their back.
        $this->assertSame($before, Section::findOrFail($section->id)->getRawOriginal('content'));
        $this->assertSame(1, Section::where('masjid_id', $this->community->id)->count());
    }

    // ----------------------------------------------------------------- HTTP

    #[Test]
    public function the_endpoint_returns_the_report_with_its_meta(): void
    {
        $admin = $this->makeAdminFor($this->community);
        $this->makeGift($this->community, 2500, '2026-05-01');

        Sanctum::actingAs($admin);

        $response = $this->getJson($this->url($this->community) . '?from=2026-01-01&to=2026-06-30')->assertOk();

        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('meta.org_type', Masjid::ORG_TYPE_COMMUNITY);
        $response->assertJsonPath('meta.period.from', '2026-01-01');
        $response->assertJsonPath('meta.currency', 'USD');

        $total = collect($response->json('data.metrics'))
            ->firstWhere('key', ImpactMetrics::DONATIONS_TOTAL);

        $this->assertSame(2500, $total['value']);
        $this->assertSame('USD 25.00', $total['formatted']);
    }

    #[Test]
    public function an_inverted_range_is_a_422(): void
    {
        Sanctum::actingAs($this->makeAdminFor($this->community));

        $this->getJson($this->url($this->community) . '?from=2026-06-30&to=2026-01-01')
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed');
    }

    #[Test]
    public function an_admin_without_view_donations_gets_the_report_without_the_money(): void
    {
        $admin = $this->makeAdminFor($this->community);
        $this->makeGift($this->community, 2500, '2026-05-01');

        // The money split is per-METRIC rather than per-route, because one
        // report cannot be assembled from two routes without making the admin
        // do it. Revoked from the bridged role, then the spatie cache cleared,
        // so the gate below is decided by the real permission set.
        Role::findByName('masjid-admin')->revokePermissionTo('view donations');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($admin);

        $response = $this->getJson($this->url($this->community))->assertOk();

        $keys = collect($response->json('data.metrics'))->pluck('key');

        $this->assertFalse($keys->contains(ImpactMetrics::DONATIONS_TOTAL));
        $this->assertTrue($keys->contains(ImpactMetrics::FORM_SUBMISSIONS));
        $this->assertSame(
            ImpactMetrics::OMITTED_PERMISSION,
            collect($response->json('meta.omitted'))
                ->firstWhere('key', ImpactMetrics::DONATIONS_TOTAL)['reason']
        );
    }

    private function url(Masjid $masjid): string
    {
        return '/api/admin/masjids/' . $masjid->id . '/impact/report';
    }
}
