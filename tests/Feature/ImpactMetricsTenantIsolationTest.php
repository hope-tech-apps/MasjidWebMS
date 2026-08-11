<?php

namespace Tests\Feature;

use App\Models\AppointmentRequest;
use App\Models\Contact;
use App\Models\ContactCredential;
use App\Models\Donation;
use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Fund;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Models\Offering;
use App\Models\Registrant;
use App\Models\Registration;
use App\Models\RegistrationPayment;
use App\Models\User;
use App\Support\ImpactMetrics;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * THE cross-tenant guardrail for the impact report (T-024) — mandatory for
 * anything that aggregates tenant-scoped rows (.claude/rules/tenant-scoping.md).
 *
 * It matters more here than almost anywhere else in the app. A leak in a list
 * endpoint shows an admin a row they should not see; a leak in THIS endpoint
 * puts another organization's numbers on a grant application, which is a
 * fabricated funder document. So both organizations are seeded with a FULL, and
 * deliberately different, fixture across every source the report reads, and the
 * assertion is exact equality with the tenant's own figures — not merely
 * "smaller than the total", which a partial leak would still satisfy.
 *
 * Three callers are covered, because they reach the scope three different ways:
 *
 *   1. UNBOUND (a console report, the Assistant) — ImpactMetrics binds
 *      TenantContext itself, so the answer must still be one tenant's.
 *   2. BOUND, as ResolveMasjidTenant leaves it on an admin request.
 *   3. BOUND TO SOMEONE ELSE — refused outright, never silently overridden.
 *
 * Plus the HTTP layer: A's admin aiming at B's URL is a 403 from
 * ResolveMasjidTenant, and A's own URL returns only A's numbers.
 *
 * Sqlite-in-memory + RefreshDatabase per the testing convention, with the clock
 * frozen so "today" (and therefore every as-of figure) is a fixed date.
 */
class ImpactMetricsTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $tenant;

    private Masjid $masjidA;
    private Masjid $masjidB;

    private User $adminA;

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

        // 12:00 UTC is 08:00 EDT the same day, so "today" is unambiguously
        // 2026-08-05 in both frames and no as-of assertion depends on which
        // side of midnight the runner sits on. Same device as DonationMetricsTest.
        $this->travelTo(Carbon::parse('2026-08-05 12:00:00', 'UTC'));

        // The report reaches CRM routes, which are gated per-route by the
        // contacts permissions; the bridged masjid-admin role must exist first.
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->tenant = app(TenantContext::class);
        // Every test starts UNBOUND; seeding then honors the explicit masjid_id.
        $this->tenant->forgetTenant();

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();

        $this->adminA = $this->makeAdminFor($this->masjidA);
        $this->makeAdminFor($this->masjidB);

        // Deliberately different volumes per tenant, and every number distinct
        // from every other, so a leak cannot hide behind a coincidence.
        $this->seedFixture($this->masjidA, appointments: 3, scheduled: 2, closed: 1, credentials: 2, expiredCredentials: 1, groups: 2, participants: 3, giftCents: 50000, submissions: 4, entriesEach: 2, registrations: 2, registrantsEach: 2, feeMinor: 15000);
        $this->seedFixture($this->masjidB, appointments: 7, scheduled: 5, closed: 4, credentials: 6, expiredCredentials: 3, groups: 5, participants: 6, giftCents: 90000, submissions: 9, entriesEach: 3, registrations: 4, registrantsEach: 3, feeMinor: 22000);
    }

    // ------------------------------------------------------------- fixtures

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
            // Community, so every metric is in the default set and a leak in
            // any one of them is visible rather than filtered away.
            'org_type' => Masjid::ORG_TYPE_COMMUNITY,
            // The report lives in the CRM route group (masjids.crm_enabled).
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

    /**
     * One organization's whole world: intake, volunteers, giving, forms,
     * groups, programs and registrations. Seeded UNBOUND so the explicit
     * masjid_id is honored rather than stamped by the creating hook.
     */
    private function seedFixture(
        Masjid $masjid,
        int $appointments,
        int $scheduled,
        int $closed,
        int $credentials,
        int $expiredCredentials,
        int $groups,
        int $participants,
        int $giftCents,
        int $submissions,
        int $entriesEach,
        int $registrations,
        int $registrantsEach,
        int $feeMinor,
    ): void {
        AppointmentRequest::factory()->count($appointments)->create([
            'masjid_id' => $masjid->id,
            'status' => AppointmentRequest::STATUS_NEW,
        ]);
        AppointmentRequest::factory()->count($scheduled)->create([
            'masjid_id' => $masjid->id,
            'status' => AppointmentRequest::STATUS_SCHEDULED,
        ]);
        AppointmentRequest::factory()->count($closed)->create([
            'masjid_id' => $masjid->id,
            'status' => AppointmentRequest::STATUS_CLOSED,
        ]);

        // One credential per person, so "volunteers" and "valid records" are
        // the same number and a join that dropped or duplicated rows shows up.
        for ($i = 0; $i < $credentials; $i++) {
            ContactCredential::factory()->create([
                'masjid_id' => $masjid->id,
                'contact_id' => Contact::factory()->create(['masjid_id' => $masjid->id])->id,
            ]);
        }

        for ($i = 0; $i < $expiredCredentials; $i++) {
            ContactCredential::factory()->expired()->create([
                'masjid_id' => $masjid->id,
                'contact_id' => Contact::factory()->create(['masjid_id' => $masjid->id])->id,
            ]);
        }

        $fund = Fund::factory()->create(['masjid_id' => $masjid->id]);
        Donation::factory()->create([
            'masjid_id' => $masjid->id,
            'fund_id' => $fund->id,
            'contact_id' => Contact::factory()->create(['masjid_id' => $masjid->id])->id,
            'status' => 'succeeded',
            'source' => 'stripe',
            'charged_amount' => $giftCents,
            'intended_amount' => $giftCents,
            'net_amount' => null,
            'donated_at' => null,
        ]);

        $form = Form::factory()->create(['masjid_id' => $masjid->id]);
        for ($i = 0; $i < $submissions; $i++) {
            FormResponse::create([
                'form_id' => $form->id,
                'masjid_id' => $masjid->id,
                'data' => ['full_name' => 'Respondent ' . $i],
                'entry_count' => $entriesEach,
                'submitted_at' => now(),
            ]);
        }

        $firstGroup = null;
        for ($i = 0; $i < $groups; $i++) {
            $group = Group::factory()->create(['masjid_id' => $masjid->id, 'is_active' => true]);
            $firstGroup ??= $group;
        }

        for ($i = 0; $i < $participants; $i++) {
            GroupMembership::create([
                'masjid_id' => $masjid->id,
                'group_id' => $firstGroup->id,
                'contact_id' => Contact::factory()->create(['masjid_id' => $masjid->id])->id,
                'role' => GroupMembership::ROLE_MEMBER,
            ]);
        }

        $offering = Offering::factory()->forMasjid($masjid)->create();

        for ($i = 0; $i < $registrations; $i++) {
            $registration = Registration::factory()->paid()->create([
                'masjid_id' => $masjid->id,
                'offering_id' => $offering->id,
            ]);

            Registrant::factory()->count($registrantsEach)->create([
                'masjid_id' => $masjid->id,
                'registration_id' => $registration->id,
            ]);

            RegistrationPayment::factory()->create([
                'masjid_id' => $masjid->id,
                'registration_id' => $registration->id,
                'amount_minor' => $feeMinor,
                'paid_at' => now(),
            ]);
        }
    }

    /** The report as a key => value map, for terse assertions. */
    private function valuesFor(Masjid $masjid): array
    {
        $report = ImpactMetrics::forMasjid($masjid)->report();

        return collect($report['metrics'])->pluck('value', 'key')->all();
    }

    /** What $masjid's own fixture says each metric must be. */
    private function expectedFor(Masjid $masjid): array
    {
        return $masjid->is($this->masjidA)
            ? [
                ImpactMetrics::APPOINTMENT_REQUESTS_RECEIVED => 6,   // 3 + 2 + 1
                ImpactMetrics::APPOINTMENT_REQUESTS_SCHEDULED => 2,
                ImpactMetrics::APPOINTMENT_REQUESTS_CLOSED => 1,
                ImpactMetrics::CREDENTIALED_VOLUNTEERS => 2,
                ImpactMetrics::CREDENTIALS_VALID => 2,
                ImpactMetrics::CREDENTIALS_EXPIRED => 1,
                ImpactMetrics::DONATIONS_TOTAL => 50000,
                ImpactMetrics::DONORS_IDENTIFIED => 1,
                ImpactMetrics::DONATIONS_COUNT => 1,
                ImpactMetrics::FORM_SUBMISSIONS => 4,
                ImpactMetrics::FORM_SUBMISSION_PEOPLE => 8,          // 4 x 2
                ImpactMetrics::ACTIVE_GROUPS => 2,
                ImpactMetrics::GROUP_PARTICIPANTS => 3,
                ImpactMetrics::ACTIVE_OFFERINGS => 1,
                ImpactMetrics::REGISTRATIONS_CONFIRMED => 2,
                ImpactMetrics::REGISTRATION_PARTICIPANTS => 4,       // 2 x 2
                ImpactMetrics::PROGRAM_FEES_COLLECTED => 30000,      // 2 x 15000
            ]
            : [
                ImpactMetrics::APPOINTMENT_REQUESTS_RECEIVED => 16,  // 7 + 5 + 4
                ImpactMetrics::APPOINTMENT_REQUESTS_SCHEDULED => 5,
                ImpactMetrics::APPOINTMENT_REQUESTS_CLOSED => 4,
                ImpactMetrics::CREDENTIALED_VOLUNTEERS => 6,
                ImpactMetrics::CREDENTIALS_VALID => 6,
                ImpactMetrics::CREDENTIALS_EXPIRED => 3,
                ImpactMetrics::DONATIONS_TOTAL => 90000,
                ImpactMetrics::DONORS_IDENTIFIED => 1,
                ImpactMetrics::DONATIONS_COUNT => 1,
                ImpactMetrics::FORM_SUBMISSIONS => 9,
                ImpactMetrics::FORM_SUBMISSION_PEOPLE => 27,         // 9 x 3
                ImpactMetrics::ACTIVE_GROUPS => 5,
                ImpactMetrics::GROUP_PARTICIPANTS => 6,
                ImpactMetrics::ACTIVE_OFFERINGS => 1,
                ImpactMetrics::REGISTRATIONS_CONFIRMED => 4,
                ImpactMetrics::REGISTRATION_PARTICIPANTS => 12,      // 4 x 3
                ImpactMetrics::PROGRAM_FEES_COLLECTED => 88000,      // 4 x 22000
            ];
    }

    // ------------------------------------------------------------ isolation

    #[Test]
    public function an_unbound_caller_still_gets_exactly_one_organizations_numbers(): void
    {
        // The console/Assistant path: nothing has bound a tenant. ImpactMetrics
        // binds one itself, which is what stops a report from silently summing
        // the whole fleet under one organization's name.
        $this->assertFalse($this->tenant->hasTenant());

        $this->assertSame($this->expectedFor($this->masjidA), $this->valuesFor($this->masjidA));
        $this->assertSame($this->expectedFor($this->masjidB), $this->valuesFor($this->masjidB));
    }

    #[Test]
    public function a_bound_caller_gets_the_same_numbers_as_an_unbound_one(): void
    {
        $this->tenant->set($this->masjidA->id);

        $this->assertSame($this->expectedFor($this->masjidA), $this->valuesFor($this->masjidA));
    }

    #[Test]
    public function no_metric_of_one_organization_ever_includes_another(): void
    {
        // Metric by metric, so a failure NAMES the aggregate that leaked rather
        // than dumping two arrays. Every fixture quantity differs between the
        // two tenants, so a metric that summed both would land on neither
        // expectation — "smaller than the fleet total" would not be enough.
        $a = $this->valuesFor($this->masjidA);
        $b = $this->valuesFor($this->masjidB);

        $expectedA = $this->expectedFor($this->masjidA);
        $expectedB = $this->expectedFor($this->masjidB);

        foreach ($expectedA as $key => $value) {
            $this->assertSame($value, $a[$key], "metric {$key} is wrong for organization A");
            $this->assertSame($expectedB[$key], $b[$key], "metric {$key} is wrong for organization B");
        }
    }

    #[Test]
    public function reporting_on_one_organization_while_another_is_bound_is_refused(): void
    {
        // A programmer error, not a user path: it would mean a controller
        // resolved one masjid from the route while the middleware bound
        // another. Refused loudly rather than quietly won, because "quietly
        // won" is how one org's figures reach another's grant application.
        $this->tenant->set($this->masjidB->id);

        $this->expectException(RuntimeException::class);

        ImpactMetrics::forMasjid($this->masjidA)->report();
    }

    #[Test]
    public function a_soft_deleted_person_leaves_the_people_counts(): void
    {
        // Not isolation, but the same class of silent wrongness: a raw join
        // sees no soft-delete scope, so a removed volunteer would keep counting
        // toward a funder figure forever.
        $before = $this->valuesFor($this->masjidA);

        // Explicitly a person whose credential is still VALID, so the drop can
        // only come from the soft-delete filter and not from an expiry.
        $stillValid = ContactCredential::withoutMasjidScope()
            ->where('masjid_id', $this->masjidA->id)
            ->where('expires_at', '>=', Carbon::today()->toDateString())
            ->firstOrFail();

        Contact::withoutMasjidScope()->findOrFail($stillValid->contact_id)->delete();

        $after = $this->valuesFor($this->masjidA);

        $this->assertSame(
            $before[ImpactMetrics::CREDENTIALED_VOLUNTEERS] - 1,
            $after[ImpactMetrics::CREDENTIALED_VOLUNTEERS]
        );
        $this->assertSame(
            $before[ImpactMetrics::CREDENTIALS_VALID] - 1,
            $after[ImpactMetrics::CREDENTIALS_VALID]
        );
    }

    // ----------------------------------------------------------------- HTTP

    #[Test]
    public function the_endpoint_rejects_unauthenticated_requests(): void
    {
        $this->getJson($this->url($this->masjidA))->assertStatus(401);
    }

    #[Test]
    public function an_admin_aiming_at_another_organizations_report_is_forbidden(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->getJson($this->url($this->masjidB))->assertStatus(403);
    }

    #[Test]
    public function the_endpoint_returns_only_the_admins_own_numbers(): void
    {
        Sanctum::actingAs($this->adminA);

        $response = $this->getJson($this->url($this->masjidA))->assertOk();

        $values = collect($response->json('data.metrics'))->pluck('value', 'key')->all();

        $this->assertSame($this->expectedFor($this->masjidA), $values);
    }

    private function url(Masjid $masjid): string
    {
        return '/api/admin/masjids/' . $masjid->id . '/impact/report';
    }
}
